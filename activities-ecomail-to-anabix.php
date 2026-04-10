<?php

/**
 * Sync email campaign activities from Ecomail → Anabix CRM.
 *
 * Uses date-based incremental sync via GET /campaigns/log?date_from=...
 * One API call (paginated) fetches all events across all campaigns since
 * the last sync, regardless of campaign count. Scales to thousands of
 * campaigns without issue.
 *
 * Event type mapping:
 *   send             → "sent newsletter"
 *   open             → "opened newsletter"
 *   click            → "clicked newsletter"
 *   hard_bounce      → note
 *   soft_bounce      → note
 *   out_of_band      → note
 *   unsub            → note
 *   spam             → note
 *   spam_complaint   → note
 *
 * Activity body format:
 *   Stav: {event}
 *   Předmět: {campaign subject}
 *   Od: {from_name} | {from_email}
 *   {archive_url}
 *
 * Usage:
 *   php activities-ecomail-to-anabix.php                       (incremental, execute)
 *   php activities-ecomail-to-anabix.php --dry-run             (preview only)
 *   php activities-ecomail-to-anabix.php --full                (ignore checkpoint)
 *   php activities-ecomail-to-anabix.php --since=2025-01-01    (from specific date)
 *
 *   Browser: activities-ecomail-to-anabix.php?dry-run=1&full=1&since=2025-01-01
 */

// ── Error reporting (always show errors for diagnostics) ─────────────
error_reporting(E_ALL);
ini_set('display_errors', '1');

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
    $debug = in_array('--debug', $argv ?? [], true);
    $sinceOverride = null;
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, '--since=')) {
            $sinceOverride = substr($arg, 8);
        }
    }
} else {
    $dryRun = ($_GET['dry-run'] ?? '') === '1';
    $fullRun = ($_GET['full'] ?? '') === '1';
    $debug = ($_GET['debug'] ?? '') === '1';
    $sinceOverride = $_GET['since'] ?? null;
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

// ── Event type mapping ────────────────────────────────────────────────

$eventTypeMap = [
    'send'           => 'sent newsletter',
    'open'           => 'opened newsletter',
    'click'          => 'clicked newsletter',
    'hard_bounce'    => 'note',
    'soft_bounce'    => 'note',
    'out_of_band'    => 'note',
    'unsub'          => 'note',
    'spam'           => 'note',
    'spam_complaint' => 'note',
];

// ── State (checkpoint + dedup) ────────────────────────────────────────

$stateDir = __DIR__ . '/storage/state';
if (!is_dir($stateDir)) {
    mkdir($stateDir, 0755, true);
}
$stateFile = $stateDir . '/activities-sync-state.json';

$state = [];
if (file_exists($stateFile)) {
    $state = json_decode(file_get_contents($stateFile), true) ?: [];
}

$lastSyncDate = $state['last_sync_date'] ?? null;  // YYYY-MM-DD
$processedEventIds = $state['processed_event_ids'] ?? [];

// Determine date_from for this run
if ($sinceOverride !== null && $sinceOverride !== '') {
    $dateFrom = $sinceOverride;
} elseif ($fullRun) {
    $dateFrom = null;  // full historical sync
    $processedEventIds = [];  // reset dedup
} else {
    $dateFrom = $lastSyncDate;  // incremental
}

// ── Helper ────────────────────────────────────────────────────────────

function output(string $msg): void
{
    $time = date('H:i:s');
    echo "[{$time}] {$msg}" . PHP_EOL;
    if (php_sapi_name() !== 'cli') {
        flush();
    }
}

// ── Run sync ──────────────────────────────────────────────────────────

output("=== Ecomail activities → Anabix ===");
if ($dryRun) {
    output("DRY RUN — no changes will be made in Anabix.");
}
if ($fullRun) {
    output("Run: FULL (all historical events, dedup reset)");
} elseif ($dateFrom !== null) {
    output("Run: INCREMENTAL (events from {$dateFrom})");
} else {
    output("Run: FIRST RUN (no checkpoint, all events)");
}

$report = [
    'status' => 'ok',
    'events_fetched' => 0,
    'activities_created' => 0,
    'skipped_no_anabix_id' => 0,
    'skipped_duplicate' => 0,
    'skipped_unmapped' => 0,
    'failed' => 0,
    'errors' => [],
];

$runStartedAt = date('Y-m-d H:i:s');

try {
    // ── 0. Debug mode: raw API probes ─────────────────────────────────

    if ($debug) {
        output("");
        output("=== DEBUG: raw API probes ===");

        // Probe 1: /campaigns/log with no filters
        output("Probe 1: GET /campaigns/log?per_page=5 (no filters)");
        $probe1 = $ecomail->debugGet('/campaigns/log', ['per_page' => 5]);
        output("  HTTP: {$probe1['_debug_http_code']}");
        output("  URL:  {$probe1['_debug_url']}");
        output("  Body: " . mb_substr($probe1['_debug_body'], 0, 600));
        output("");

        // Probe 2: /campaigns/log filtered by campaign_id=3 (the only real campaign)
        output("Probe 2: GET /campaigns/log?campaign_id=3&per_page=5");
        $probe2 = $ecomail->debugGet('/campaigns/log', ['campaign_id' => 3, 'per_page' => 5]);
        output("  HTTP: {$probe2['_debug_http_code']}");
        output("  URL:  {$probe2['_debug_url']}");
        output("  Body: " . mb_substr($probe2['_debug_body'], 0, 600));
        output("");

        // Probe 3: /campaigns/3/stats-detail (alternative: per-campaign aggregates)
        output("Probe 3: GET /campaigns/3/stats-detail?per_page=5");
        $probe3 = $ecomail->debugGet('/campaigns/3/stats-detail', ['per_page' => 5]);
        output("  HTTP: {$probe3['_debug_http_code']}");
        output("  URL:  {$probe3['_debug_url']}");
        output("  Body: " . mb_substr($probe3['_debug_body'], 0, 600));
        output("");

        // Probe 4: /campaigns/log filtered by known subscriber email
        output("Probe 4: GET /campaigns/log?email=marek@optimal-marketing.cz&per_page=5");
        $probe4 = $ecomail->debugGet('/campaigns/log', ['email' => 'marek@optimal-marketing.cz', 'per_page' => 5]);
        output("  HTTP: {$probe4['_debug_http_code']}");
        output("  URL:  {$probe4['_debug_url']}");
        output("  Body: " . mb_substr($probe4['_debug_body'], 0, 600));
        output("");

        output("=== END DEBUG ===");
        output("");
        goto finish;
    }

    // ── 1. Fetch all events via /campaigns/log ────────────────────────

    $filters = [];
    if ($dateFrom !== null && $dateFrom !== '') {
        $filters['date_from'] = $dateFrom;
    }

    output("Fetching campaign events from Ecomail...");
    output("  Filters: " . (empty($filters) ? '(none)' : json_encode($filters)));

    $events = $ecomail->getCampaignLog($filters);
    $report['events_fetched'] = count($events);

    output("  Fetched " . count($events) . " event(s).");

    if (empty($events)) {
        output("No events to process.");
        goto finish;
    }

    // ── 2. Fetch campaigns once, build lookup map ─────────────────────

    output("Loading campaign details for lookup...");
    $campaigns = $ecomail->getCampaigns();
    $campaignMap = [];
    foreach ($campaigns as $c) {
        $cid = $c['id'] ?? $c['campaign_id'] ?? null;
        if ($cid !== null) {
            $campaignMap[(int) $cid] = $c;
        }
    }
    output("  Loaded " . count($campaignMap) . " campaign(s).");

    // ── 3. Process events ─────────────────────────────────────────────

    $anabixIdCache = [];  // email → anabixId (cached across whole run)

    foreach ($events as $event) {
        $eventId = $event['id'] ?? null;
        $email = $event['email'] ?? '';
        $eventType = $event['event'] ?? '';
        $campaignId = $event['campaign_id'] ?? null;

        if ($eventId === null || $email === '' || $eventType === '') {
            continue;
        }

        // Dedup by Ecomail event ID (stable, unique)
        if (isset($processedEventIds[$eventId])) {
            $report['skipped_duplicate']++;
            continue;
        }

        // Map event type to Anabix activity type
        $activityType = $eventTypeMap[$eventType] ?? null;
        if ($activityType === null) {
            $report['skipped_unmapped']++;
            continue;
        }

        // Get anabixId (cached lookup via Ecomail subscriber)
        if (!array_key_exists($email, $anabixIdCache)) {
            $subscriber = $ecomail->getSubscriber($email);
            $anabixIdCache[$email] = $subscriber['custom_fields']['anabixId']
                ?? $subscriber['merge_fields']['anabixId']
                ?? null;
            usleep(150000);
        }

        $anabixId = $anabixIdCache[$email];
        if ($anabixId === null || (int) $anabixId === 0) {
            $report['skipped_no_anabix_id']++;
            continue;
        }

        // Lookup campaign details for title/subject/from/archive_url
        $campaign = $campaignMap[(int) $campaignId] ?? [];
        $subject = $campaign['subject']
            ?? $campaign['title']
            ?? $event['mail_name']
            ?? "(kampaň #{$campaignId})";
        $title = $campaign['title'] ?? $subject;
        $fromName = $campaign['from_name'] ?? '';
        $fromEmail = $campaign['from_email'] ?? '';
        $archiveUrl = $campaign['archive_url'] ?? '';

        // Build activity body
        $bodyLines = ["Stav: {$eventType}"];
        $bodyLines[] = "Předmět: {$subject}";
        if ($fromName !== '' || $fromEmail !== '') {
            $bodyLines[] = "Od: {$fromName} | {$fromEmail}";
        }
        if (!empty($event['url'])) {
            $bodyLines[] = "URL: {$event['url']}";
        }
        if ($archiveUrl !== '') {
            $bodyLines[] = $archiveUrl;
        }
        $body = implode("\n", $bodyLines);

        $timestamp = $event['occured_at'] ?? null;

        if ($dryRun) {
            output("  [DRY] {$email} (anabixId={$anabixId}): {$eventType} → {$activityType} | {$title}");
            $report['activities_created']++;
            $processedEventIds[$eventId] = true;
            continue;
        }

        // Create activity in Anabix
        $result = $anabix->createActivity(
            (int) $anabixId,
            $title,
            $body,
            $activityType,
            $timestamp,
            $activityIdUser
        );

        if ($result !== null) {
            $report['activities_created']++;
            $processedEventIds[$eventId] = true;

            // Save checkpoint every 50 created activities
            if ($report['activities_created'] % 50 === 0) {
                $state['processed_event_ids'] = $processedEventIds;
                file_put_contents(
                    $stateFile,
                    json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                    LOCK_EX
                );
            }
        } else {
            $report['failed']++;
            $report['errors'][] = "Failed: eventId={$eventId} email={$email} event={$eventType}";
        }

        usleep(150000); // ~7 req/s rate limit
    }

} catch (Throwable $e) {
    $report['status'] = 'error';
    $report['errors'][] = $e->getMessage();
    output("FATAL ERROR: " . $e->getMessage());
    $logger->error("Activities sync failed", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}

finish:

// ── Save state ────────────────────────────────────────────────────────

if (!$dryRun) {
    $state['last_sync_date'] = date('Y-m-d');  // next run picks up from here
    $state['last_sync_started_at'] = $runStartedAt;
    $state['last_sync_finished_at'] = date('Y-m-d H:i:s');
    $state['processed_event_ids'] = $processedEventIds;

    file_put_contents(
        $stateFile,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );

    output("");
    output("Checkpoint saved: last_sync_date={$state['last_sync_date']}");
    output("Dedup event IDs stored: " . count($processedEventIds));
}

// ── Summary ──────────────────────────────────────────────────────────

output("");
output("=== Summary ===");
output("Events fetched:  {$report['events_fetched']}");
output("Created:         {$report['activities_created']}");
output("No anabixId:     {$report['skipped_no_anabix_id']}");
output("Duplicates:      {$report['skipped_duplicate']}");
output("Unmapped:        {$report['skipped_unmapped']}");
output("Failed:          {$report['failed']}");
output("Status:          {$report['status']}");

$logger->info("Activities sync completed", $report);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
