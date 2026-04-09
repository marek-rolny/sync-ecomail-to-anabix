<?php

/**
 * Sync email campaign activities from Ecomail → Anabix CRM.
 *
 * Reads campaigns and subscriber events from Ecomail,
 * maps them to Anabix contacts via the *|anabixId|* custom field,
 * and creates activities in Anabix.
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
 *   php activities-ecomail-to-anabix.php
 *   php activities-ecomail-to-anabix.php --dry-run
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
} else {
    $dryRun = ($_GET['dry-run'] ?? '') === '1';
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

// ── State for deduplication ───────────────────────────────────────────

$stateFile = __DIR__ . '/storage/state/activities-sync-state.json';
$processedKeys = [];
if (file_exists($stateFile)) {
    $processedKeys = json_decode(file_get_contents($stateFile), true) ?: [];
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

$report = [
    'status' => 'ok',
    'campaigns_processed' => 0,
    'activities_created' => 0,
    'skipped_no_anabix_id' => 0,
    'skipped_duplicate' => 0,
    'failed' => 0,
    'errors' => [],
];

try {
    // ── Fetch campaigns ───────────────────────────────────────────────

    output("Fetching campaigns from Ecomail...");
    $campaigns = $ecomail->getCampaigns('sent');

    if (empty($campaigns)) {
        output("No sent campaigns found.");
        goto finish;
    }

    output("Found " . count($campaigns) . " sent campaign(s).");

    // ── Process each campaign ─────────────────────────────────────────

    foreach ($campaigns as $campaign) {
        $campaignId = $campaign['id'] ?? $campaign['campaign_id'] ?? null;

        if ($campaignId === null) {
            continue;
        }

        $subject = $campaign['subject'] ?? $campaign['title'] ?? '(no subject)';
        $title = $campaign['title'] ?? $subject;
        $fromName = $campaign['from_name'] ?? '';
        $fromEmail = $campaign['from_email'] ?? '';
        $sentAt = $campaign['sent_at'] ?? $campaign['send_at'] ?? null;
        $archiveUrl = $campaign['archive_url'] ?? '';

        output("Campaign #{$campaignId}: {$subject}");

        // Fetch campaign log — individual events per subscriber
        $events = $ecomail->getCampaignLog((int) $campaignId);

        if (empty($events)) {
            output("  No events in campaign log.");
            $report['campaigns_processed']++;
            continue;
        }

        output("  Events: " . count($events));

        // Cache anabixId lookups to avoid repeated API calls for same email
        $anabixIdCache = [];

        foreach ($events as $event) {
            $email = $event['email'] ?? '';
            $eventType = $event['event'] ?? '';

            if ($eventType === '' || $email === '') {
                continue;
            }

            // Map event type to Anabix activity type
            $activityType = $eventTypeMap[$eventType] ?? null;
            if ($activityType === null) {
                continue;
            }

            // Get anabixId — use cache or fetch from Ecomail subscriber
            if (!array_key_exists($email, $anabixIdCache)) {
                $subscriber = $ecomail->getSubscriber($email);
                $anabixIdCache[$email] = $subscriber['custom_fields']['anabixId']
                    ?? $subscriber['merge_fields']['anabixId']
                    ?? null;
                usleep(200000);
            }

            $anabixId = $anabixIdCache[$email];

            if ($anabixId === null || (int) $anabixId === 0) {
                $report['skipped_no_anabix_id']++;
                continue;
            }

            // Deduplication key: campaign + contact + event type
            $stateKey = md5("{$campaignId}|{$anabixId}|{$eventType}");
            if (isset($processedKeys[$stateKey])) {
                $report['skipped_duplicate']++;
                continue;
            }

            // Build activity body
            $body = "Stav: {$eventType}\n"
                . "Předmět: {$subject}\n"
                . "Od: {$fromName} | {$fromEmail}";
            if ($archiveUrl !== '') {
                $body .= "\n{$archiveUrl}";
            }

            $activityTitle = $title;
            $timestamp = $event['occured_at'] ?? $sentAt ?? date('Y-m-d H:i:s');

            if ($dryRun) {
                output("  [DRY] {$email} (anabixId={$anabixId}): {$eventType} → {$activityType}");
                $report['activities_created']++;
                $processedKeys[$stateKey] = true;
                continue;
            }

            // Create activity in Anabix
            $result = $anabix->createActivity(
                (int) $anabixId,
                $activityTitle,
                $body,
                $activityType,
                $timestamp,
                $activityIdUser
            );

            if ($result !== null) {
                $report['activities_created']++;
                $processedKeys[$stateKey] = true;
            } else {
                $report['failed']++;
                $report['errors'][] = "Failed: campaign={$campaignId} email={$email} event={$eventType}";
            }

            usleep(200000); // rate limiting
        }

        $report['campaigns_processed']++;

        // Save state after each campaign (incremental)
        if (!$dryRun) {
            file_put_contents(
                $stateFile,
                json_encode($processedKeys, JSON_PRETTY_PRINT),
                LOCK_EX
            );
        }
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

// ── Summary ──────────────────────────────────────────────────────────

output("");
output("=== Summary ===");
output("Campaigns:     {$report['campaigns_processed']}");
output("Created:       {$report['activities_created']}");
output("No anabixId:   {$report['skipped_no_anabix_id']}");
output("Duplicates:    {$report['skipped_duplicate']}");
output("Failed:        {$report['failed']}");
output("Status:        {$report['status']}");

$logger->info("Activities sync completed", $report);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

// ── Helpers ──────────────────────────────────────────────────────────

