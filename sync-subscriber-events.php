<?php

/**
 * Sync web tracker events from Ecomail → Anabix CRM.
 *
 * Iterates subscribers in the configured Ecomail list, fetches each
 * subscriber's tracker events (/subscribers/{email}/events), and creates
 * "Návštěva webu" notes in Anabix for new events (checkpoint dedup).
 *
 * Scope:
 *   - Only website tracker data (page views, basket, purchase, custom events).
 *   - Campaign (newsletter) events are handled by activities-ecomail-to-anabix.php.
 *   - Automation/autoresponder events are handled by sync-automation-events.php.
 *
 * Activity format:
 *   title = "Návštěva webu {domain}"
 *   type  = note
 *   body  = URL: ..., Akce: ..., Kategorie: ..., Datum: ...
 *
 * Usage:
 *   php sync-subscriber-events.php                (execute)
 *   php sync-subscriber-events.php --dry-run      (preview only)
 *   php sync-subscriber-events.php --full         (ignore checkpoint, process all)
 *   php sync-subscriber-events.php --email=X      (test single subscriber)
 *   Browser: sync-subscriber-events.php?dry-run=1&full=1
 */

// ── Deploy smoke-test endpoint (must stay at top, no dependencies) ───
// Usage: GET ?ping=1 → JSON {script, mtime, status}. Used by CI
// to verify that the file was deployed and PHP is able to execute it.
if (php_sapi_name() !== 'cli' && ($_GET['ping'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'script' => basename(__FILE__),
        'mtime'  => date('c', (int) @filemtime(__FILE__)),
        'status' => 'ok',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Web compatibility: prevent proxy timeout ─────────────────────────
set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache');
    ini_set('output_buffering', '0');
    ini_set('zlib.output_compression', '0');
    if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
    }
    while (ob_get_level()) {
        ob_end_flush();
    }
    ob_implicit_flush(true);

    echo str_repeat(' ', 8192) . "\n";
    flush();
}

require_once __DIR__ . '/src/env.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/AnabixClient.php';
require_once __DIR__ . '/src/EcomailClient.php';

// ── Load configuration ────────────────────────────────────────────────

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    echo "Error: .env file not found.\n";
    exit(1);
}
loadEnv($envFile);

// ── Validate ──────────────────────────────────────────────────────────

$required = ['ANABIX_USERNAME', 'ANABIX_TOKEN', 'ANABIX_API_URL', 'ECOMAIL_API_KEY', 'ECOMAIL_LIST_ID'];
foreach ($required as $var) {
    if (env($var) === '') {
        echo "Error: {$var} is not set in .env\n";
        exit(1);
    }
}

// ── CLI / web arguments ──────────────────────────────────────────────

if (php_sapi_name() === 'cli') {
    $dryRun = in_array('--dry-run', $argv ?? [], true);
    $fullRun = in_array('--full', $argv ?? [], true);
    $testEmail = null;
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, '--email=')) {
            $testEmail = substr($arg, 8);
        }
    }
} else {
    $dryRun = ($_GET['dry-run'] ?? '') === '1';
    $fullRun = ($_GET['full'] ?? '') === '1';
    $testEmail = $_GET['email'] ?? null;
}

// ── Initialize ────────────────────────────────────────────────────────

$logger = new Logger(__DIR__ . '/storage/logs');

$ecomail = new EcomailClient(
    env('ECOMAIL_API_KEY'),
    env('ECOMAIL_API_URL', 'https://api2.ecomailapp.cz'),
    (int) env('ECOMAIL_LIST_ID'),
    $logger
);

$anabix = new AnabixClient(
    env('ANABIX_USERNAME'),
    env('ANABIX_TOKEN'),
    env('ANABIX_API_URL'),
    $logger
);

$activityIdUser = env('ANABIX_ACTIVITY_ID_USER', '') !== ''
    ? (int) env('ANABIX_ACTIVITY_ID_USER')
    : null;

// ── Raw debug mode ───────────────────────────────────────────────────
// ?raw=1&email=X — bypasses all sync logic; calls several candidate
// Ecomail endpoints for a single subscriber and dumps the HTTP status +
// first ~4KB of body. Lets us see what Ecomail actually returns for
// web tracker events without trusting any of our parsing code.
if (php_sapi_name() !== 'cli' && ($_GET['raw'] ?? '') === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    $email = $_GET['email'] ?? '';
    if ($email === '') {
        echo "Usage: ?raw=1&email=<subscriber-email>\n";
        exit;
    }

    $enc = urlencode($email);
    $candidates = [
        "/subscribers/{$enc}/events",
        "/subscribers/{$enc}/events?per_page=100",
        "/subscribers/{$enc}",
        "/tracker/events?email={$enc}",
        "/tracker/events?email={$enc}&per_page=100",
        "/events?email={$enc}",
    ];

    foreach ($candidates as $endpoint) {
        echo str_repeat('=', 70) . "\n";
        echo "GET {$endpoint}\n";
        echo str_repeat('=', 70) . "\n";

        $result = $ecomail->debugGet($endpoint);
        if ($result === null) {
            echo "  (debugGet returned null — curl error)\n\n";
            continue;
        }
        echo "HTTP {$result['_debug_http_code']}\n";
        if (!empty($result['_debug_curl_error'])) {
            echo "CURL ERROR: {$result['_debug_curl_error']}\n";
        }

        // Prefer pretty-printed parsed JSON (full response); fall back
        // to truncated raw body if the response isn't JSON.
        $parsed = $result['_debug_parsed'] ?? null;
        if (is_array($parsed)) {
            $pretty = json_encode(
                $parsed,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            echo mb_substr($pretty, 0, 4000);
        } else {
            echo "(non-JSON body)\n";
            echo mb_substr((string) ($result['_debug_body'] ?? ''), 0, 4000);
        }
        echo "\n\n";
    }
    exit;
}

// ── Checkpoint (incremental sync) ───────────────────────────────────

$stateDir = __DIR__ . '/storage/state';
$stateFile = $stateDir . '/subscriber-events.json';

$lastRun = null;
if (!$fullRun && file_exists($stateFile)) {
    $state = json_decode(file_get_contents($stateFile), true);
    $lastRun = $state['last_run'] ?? null;
}

// ── Helper ──────────────────────────────────────────────────────────

function output(string $msg): void
{
    $time = date('H:i:s');
    echo "[{$time}] {$msg}" . PHP_EOL;
    if (php_sapi_name() !== 'cli') {
        flush();
    }
}

// ── Run sync ────────────────────────────────────────────────────────

output("=== Subscriber tracker events: Ecomail → Anabix ===");
output("Mode: " . ($dryRun ? "DRY-RUN" : "EXECUTE"));
if ($fullRun) {
    output("Run: FULL (processing all events, ignoring checkpoint)");
} elseif ($lastRun !== null) {
    output("Run: INCREMENTAL (events since {$lastRun})");
} else {
    output("Run: FIRST RUN (no checkpoint, processing all events)");
}
output("");

$report = [
    'subscribers_total' => 0,
    'subscribers_with_anabix_id' => 0,
    'subscribers_skipped' => 0,
    'tracker_events' => 0,
    'activities_created' => 0,
    'skipped_duplicate' => 0,
    'failed' => 0,
    'errors' => [],
];

// Dedup state: load existing processed keys
$processedKeysFile = $stateDir . '/subscriber-events-keys.json';
$processedKeys = [];
if (!$fullRun && file_exists($processedKeysFile)) {
    $processedKeys = json_decode(file_get_contents($processedKeysFile), true) ?: [];
}

$runTimestamp = date('Y-m-d H:i:s');

try {
    // ── 1. Fetch subscribers ─────────────────────────────────────────

    if ($testEmail !== null && $testEmail !== '') {
        output("TEST MODE: single subscriber {$testEmail}");
        $sub = $ecomail->getSubscriber($testEmail);
        if ($sub === null) {
            output("Subscriber not found: {$testEmail}");
            goto finish;
        }
        $subscribers = [$sub];
    } else {
        output("Fetching subscribers from Ecomail list #" . env('ECOMAIL_LIST_ID') . "...");
        $subscribers = $ecomail->getSubscribers();
    }

    $report['subscribers_total'] = count($subscribers);
    output("Subscribers: {$report['subscribers_total']}");
    output("");

    if (empty($subscribers)) {
        output("No subscribers found.");
        goto finish;
    }

    // ── 2. Process each subscriber ───────────────────────────────────

    foreach ($subscribers as $subIndex => $subscriber) {
        $email = $subscriber['email'] ?? '';
        if ($email === '') {
            continue;
        }

        // Get anabixId from custom fields
        $anabixId = $subscriber['custom_fields']['anabixId']
            ?? $subscriber['merge_fields']['anabixId']
            ?? null;

        if ($anabixId === null || (int) $anabixId === 0) {
            $report['subscribers_skipped']++;
            continue;
        }

        $anabixId = (int) $anabixId;
        $report['subscribers_with_anabix_id']++;

        $subNum = $subIndex + 1;
        output("  [{$subNum}/{$report['subscribers_total']}] {$email} (anabixId={$anabixId})");

        // ── Tracker events (web visits, basket, purchase, etc.) ──────

        $trackerEvents = $ecomail->getSubscriberEvents($email);
        $report['tracker_events'] += count($trackerEvents);

        foreach ($trackerEvents as $event) {
            $action   = $event['action'] ?? '';
            $category = $event['category'] ?? '';
            if ($action === '' && $category === '') {
                continue;
            }

            $deduKey = md5("tracker|{$anabixId}|" . ($event['id'] ?? '') . "|{$action}|" . ($event['timestamp'] ?? ''));
            if (isset($processedKeys[$deduKey])) {
                $report['skipped_duplicate']++;
                continue;
            }

            // Extract URL from property or value JSON
            $url = '';
            $property = $event['property'] ?? '';
            if ($property !== '') {
                $url = $property;
            } else {
                $valueRaw = $event['value'] ?? '';
                if ($valueRaw !== '') {
                    $valueParsed = json_decode($valueRaw, true);
                    $url = $valueParsed['url']
                        ?? $valueParsed['data']['url']
                        ?? $valueParsed['data']['data']['url']
                        ?? '';
                }
            }

            // Title: "Návštěva webu {domain}"
            $domain = '';
            if ($url !== '') {
                $parsed = parse_url($url);
                $domain = $parsed['host'] ?? '';
                $domain = preg_replace('/^www\./', '', $domain);
            }
            $title = 'Návštěva webu' . ($domain !== '' ? " {$domain}" : '');

            $bodyLines = [];
            if ($url !== '')      { $bodyLines[] = "URL: {$url}"; }
            if ($action !== '' && $action !== 'pageview') { $bodyLines[] = "Akce: {$action}"; }
            if ($category !== '') { $bodyLines[] = "Kategorie: {$category}"; }

            $timestamp = $event['timestamp'] ?? null;
            if ($timestamp !== '') { $bodyLines[] = "Datum: {$timestamp}"; }
            $body = implode("\n", $bodyLines);

            if ($dryRun) {
                output("    [DRY] tracker note: {$title}");
                $report['activities_created']++;
                $processedKeys[$deduKey] = true;
                continue;
            }

            $result = $anabix->createActivity(
                $anabixId,
                $title,
                $body,
                'note',
                $timestamp,
                $activityIdUser
            );

            if ($result !== null) {
                $report['activities_created']++;
                $processedKeys[$deduKey] = true;
            } else {
                $report['failed']++;
                $report['errors'][] = "Failed: {$email} tracker {$action}";
            }

            usleep(200000);
        }

        // In debug mode (first run / dry-run), stop after 5 subscribers to save time
        if ($dryRun && $testEmail === null && $subIndex >= 4) {
            output("");
            output("  (DRY-RUN: stopped after 5 subscribers for quick preview)");
            break;
        }

        usleep(100000);
    }

} catch (Throwable $e) {
    $report['errors'][] = $e->getMessage();
    output("ERROR: " . $e->getMessage());
    $logger->error("Subscriber tracker events sync failed", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}

finish:

// ── Save checkpoint ─────────────────────────────────────────────────

if (!$dryRun) {
    if (!is_dir($stateDir)) {
        mkdir($stateDir, 0755, true);
    }

    // Save timestamp checkpoint
    file_put_contents($stateFile, json_encode([
        'last_run' => $runTimestamp,
        'updated_at' => date('Y-m-d H:i:s'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // Save dedup keys
    file_put_contents($processedKeysFile, json_encode(
        $processedKeys,
        JSON_UNESCAPED_UNICODE
    ));

    output("");
    output("Checkpoint saved: {$runTimestamp}");
    output("Dedup keys stored: " . count($processedKeys));
}

// ── Summary ─────────────────────────────────────────────────────────

output("");
output("=== Summary ===");
output("Subscribers total:       {$report['subscribers_total']}");
output("Subscribers with ID:     {$report['subscribers_with_anabix_id']}");
output("Subscribers skipped:     {$report['subscribers_skipped']}");
output("Tracker events:          {$report['tracker_events']}");
output("Activities created:      {$report['activities_created']}");
output("Skipped (duplicate):     {$report['skipped_duplicate']}");
output("Failed:                  {$report['failed']}");

if (!empty($report['errors'])) {
    output("");
    output("Errors:");
    foreach ($report['errors'] as $err) {
        output("  - {$err}");
    }
}

$logger->info("Subscriber tracker events sync completed", $report);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
