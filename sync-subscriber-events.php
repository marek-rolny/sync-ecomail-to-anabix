<?php

/**
 * Sync all subscriber events from Ecomail → Anabix CRM.
 *
 * Iterates subscribers, fetches their email-log (campaigns) and
 * automation-log (pipelines), and creates activities in Anabix.
 *
 * Event type mapping:
 *   send / soft_bounce / hard_bounce / out_of_band  →  see $eventTypeMap
 *   open / click / unsub / spam / spam_complaint     →  see $eventTypeMap
 *
 * Uses subscriber-centric approach:
 *   1. GET /lists/{id}/subscribers → list all subscribers
 *   2. For each subscriber with anabixId:
 *      a) GET /subscribers/{email}/email-log → campaign events
 *      b) GET /subscribers/{email}/automation-log → automation events
 *   3. Create Anabix activities for new events (checkpoint-based dedup)
 *
 * Usage:
 *   php sync-subscriber-events.php                (execute)
 *   php sync-subscriber-events.php --dry-run      (preview only)
 *   php sync-subscriber-events.php --full         (ignore checkpoint, process all)
 *   Browser: sync-subscriber-events.php?dry-run=1&full=1
 */

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

// ── Event type mapping (Ecomail → Anabix activity type) ─────────────

$eventTypeMap = [
    'send'           => 'sent autoresponder',
    'open'           => 'opened autoresponder',
    'click'          => 'clicked link in autoresponder',
    'hard_bounce'    => 'note',
    'soft_bounce'    => 'note',
    'out_of_band'    => 'note',
    'unsub'          => 'note',
    'spam'           => 'note',
    'spam_complaint' => 'note',
];

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

/**
 * Build activity body from an event record.
 */
function buildActivityBody(array $event, string $source): string
{
    $eventType = $event['event'] ?? 'unknown';
    $lines = [];
    $lines[] = "Stav: {$eventType}";
    $lines[] = "Zdroj: {$source}";

    // Campaign-specific fields
    $subject = $event['subject'] ?? $event['campaign_subject'] ?? '';
    if ($subject !== '') {
        $lines[] = "Předmět: {$subject}";
    }

    // Automation-specific fields
    $pipelineName = $event['pipeline_name'] ?? $event['automation_name'] ?? '';
    if ($pipelineName !== '') {
        $lines[] = "Automatizace: {$pipelineName}";
    }

    $actionName = $event['action_name'] ?? '';
    if ($actionName !== '') {
        $lines[] = "Akce: {$actionName}";
    }

    // Common fields
    $timestamp = $event['occured_at'] ?? $event['timestamp'] ?? $event['created_at'] ?? '';
    if ($timestamp !== '') {
        $lines[] = "Datum: {$timestamp}";
    }

    $url = $event['url'] ?? '';
    if ($url !== '') {
        $lines[] = "URL: {$url}";
    }

    $msg = $event['msg'] ?? '';
    if ($msg !== '') {
        $lines[] = "Detail: {$msg}";
    }

    return implode("\n", $lines);
}

/**
 * Get a deduplication key for an event record.
 */
function eventDeduplicationKey(array $event, string $source, int $anabixId): string
{
    $eventId = $event['id'] ?? '';
    $eventType = $event['event'] ?? $event['type'] ?? '';
    $timestamp = $event['occured_at'] ?? $event['timestamp'] ?? $event['created_at'] ?? '';
    $campaignId = $event['campaign_id'] ?? $event['pipeline_id'] ?? '';

    return md5("{$source}|{$anabixId}|{$campaignId}|{$eventType}|{$eventId}|{$timestamp}");
}

// ── Run sync ────────────────────────────────────────────────────────

output("=== Subscriber events: Ecomail → Anabix ===");
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
    'email_log_events' => 0,
    'automation_log_events' => 0,
    'activities_created' => 0,
    'skipped_duplicate' => 0,
    'skipped_unmapped' => 0,
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
        $isDebug = $subIndex < 3 || $testEmail !== null; // debug first 3 or test mode

        // ── 2a. Email log (campaign events) ──────────────────────────

        $emailLogEvents = $ecomail->getSubscriberEmailLog($email);

        if ($isDebug) {
            $debugResponse = $ecomail->debugGet("/subscribers/" . urlencode($email) . "/email-log", ['per_page' => 5]);
            output("  [{$subNum}] {$email} (anabixId={$anabixId})");
            output("    email-log HTTP {$debugResponse['_debug_http_code']}:");
            if ($debugResponse['_debug_curl_error']) {
                output("    cURL error: {$debugResponse['_debug_curl_error']}");
            }
            output("    URL: {$debugResponse['_debug_url']}");
            output("    Body: " . mb_substr($debugResponse['_debug_body'], 0, 500));
        } else {
            output("  [{$subNum}/{$report['subscribers_total']}] {$email} (anabixId={$anabixId}) — emails: " . count($emailLogEvents));
        }
        $report['email_log_events'] += count($emailLogEvents);

        foreach ($emailLogEvents as $event) {
            $eventType = $event['event'] ?? '';
            if ($eventType === '') {
                continue;
            }

            $activityType = $eventTypeMap[$eventType] ?? null;
            if ($activityType === null) {
                $report['skipped_unmapped']++;
                continue;
            }

            $deduKey = eventDeduplicationKey($event, 'campaign', $anabixId);
            if (isset($processedKeys[$deduKey])) {
                $report['skipped_duplicate']++;
                continue;
            }

            // email-log fields: campaign_id, autoresponder_id, mail_name, event, url, occured_at
            $mailName = $event['mail_name'] ?? '';
            $campaignId = $event['campaign_id'] ?? '';
            $autoresponderId = $event['autoresponder_id'] ?? '';

            if ($mailName !== '') {
                $title = $mailName;
            } elseif ($autoresponderId !== '') {
                $title = "Autoresponder #{$autoresponderId}";
            } elseif ($campaignId !== '') {
                $title = "Kampaň #{$campaignId}";
            } else {
                $title = "Email event";
            }

            $bodyLines = ["Stav: {$eventType}"];
            if ($mailName !== '') { $bodyLines[] = "Email: {$mailName}"; }
            if ($campaignId !== '') { $bodyLines[] = "Kampaň: #{$campaignId}"; }
            if ($autoresponderId !== '') { $bodyLines[] = "Autoresponder: #{$autoresponderId}"; }
            $url = $event['url'] ?? '';
            if ($url !== '') { $bodyLines[] = "URL: {$url}"; }
            $occuredAt = $event['occured_at'] ?? '';
            if ($occuredAt !== '') { $bodyLines[] = "Datum: {$occuredAt}"; }
            $body = implode("\n", $bodyLines);

            $timestamp = $event['occured_at'] ?? null;

            if ($dryRun) {
                output("    [DRY] campaign {$eventType}: {$title}");
                $report['activities_created']++;
                $processedKeys[$deduKey] = true;
                continue;
            }

            $result = $anabix->createActivity(
                $anabixId,
                $title,
                $body,
                $activityType,
                $timestamp,
                $activityIdUser
            );

            if ($result !== null) {
                $report['activities_created']++;
                $processedKeys[$deduKey] = true;
            } else {
                $report['failed']++;
                $report['errors'][] = "Failed: {$email} campaign {$eventType}";
            }

            usleep(200000);
        }

        // ── 2b. Automation log (pipeline events) ─────────────────────

        $automationLogEvents = $ecomail->getSubscriberAutomationLog($email);
        $report['automation_log_events'] += count($automationLogEvents);

        if ($isDebug) {
            output("    automation-log: " . count($automationLogEvents) . " events");
            if (!empty($automationLogEvents)) {
                $first = reset($automationLogEvents);
                output("    First event keys: " . implode(', ', array_keys($first)));
                output("    First event: " . mb_substr(json_encode($first, JSON_UNESCAPED_UNICODE), 0, 500));
            }
        }

        foreach ($automationLogEvents as $event) {
            // automation-log records have no 'event' field — they are pipeline
            // execution records: {pipeline_id, action_id, trigger_id, timestamp}
            // We create a 'note' activity for each pipeline execution.
            $pipelineId = $event['pipeline_id'] ?? '';
            $actionId   = $event['action_id'] ?? '';
            if ($pipelineId === '' && $actionId === '') {
                continue;
            }

            $deduKey = eventDeduplicationKey($event, 'automation', $anabixId);
            if (isset($processedKeys[$deduKey])) {
                $report['skipped_duplicate']++;
                continue;
            }

            $title = "Automatizace #{$pipelineId}";
            $timestamp = $event['timestamp'] ?? null;

            $bodyLines = ["Spuštěna automatizace"];
            if ($pipelineId !== '') {
                $bodyLines[] = "Pipeline: #{$pipelineId}";
            }
            if ($actionId !== '') {
                $bodyLines[] = "Akce: {$actionId}";
            }
            $triggerId = $event['trigger_id'] ?? '';
            if ($triggerId !== '') {
                $bodyLines[] = "Trigger: {$triggerId}";
            }
            if ($timestamp !== '') {
                $bodyLines[] = "Datum: {$timestamp}";
            }
            $body = implode("\n", $bodyLines);

            if ($dryRun) {
                output("    [DRY] automation note: {$title}");
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
                $report['errors'][] = "Failed: {$email} automation #{$pipelineId}";
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
    $logger->error("Subscriber events sync failed", [
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
output("Email log events:        {$report['email_log_events']}");
output("Automation log events:   {$report['automation_log_events']}");
output("Activities created:      {$report['activities_created']}");
output("Skipped (duplicate):     {$report['skipped_duplicate']}");
output("Skipped (unmapped):      {$report['skipped_unmapped']}");
output("Failed:                  {$report['failed']}");

if (!empty($report['errors'])) {
    output("");
    output("Errors:");
    foreach ($report['errors'] as $err) {
        output("  - {$err}");
    }
}

$logger->info("Subscriber events sync completed", $report);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
