<?php

/**
 * Sync email campaign activities from Ecomail → Anabix CRM.
 *
 * Uses /campaigns/{id}/stats-detail — per-subscriber aggregated counts
 * per campaign (send/open/click/bounce/...). For each non-zero event type
 * we create one Anabix activity.
 *
 * Why not /campaigns/log? Despite being documented, that endpoint returns
 * HTTP 422 "The id must be a number" because the Ecomail API routes
 * /campaigns/log as /campaigns/{id} with id="log". stats-detail is the
 * only working source of campaign events.
 *
 * Event type mapping:
 *   send             → "sent newsletter"
 *   open             → "opened newsletter"
 *   click            → "clicked link in newsletter"
 *   hard_bounce      → note
 *   soft_bounce      → note
 *   out_of_band      → note
 *   unsub            → note
 *   spam             → note
 *   spam_complaint   → note
 *
 * Activity title format:
 *   {subject} - {event}              (send, bounces, unsub, spam)
 *   {subject} - {event} ({count}×)   (open, click — count always shown)
 *
 * Activity body format:
 *   Kampaň: {campaign title} (viz Ecomail)
 *   Stav: {event}                    (or "{event} ({count}×)" for open/click)
 *   Předmět: {campaign subject}
 *   Od: {from_name} | {from_email}
 *   {archive_url}
 *
 *   Datum odeslání kampaně: {DD.MM.YYYY}
 *
 * Deduplication key:
 *   md5("{campaign_id}|{anabixId}|{event_type}")
 *
 * Usage:
 *   php activities-ecomail-to-anabix.php                       (execute)
 *   php activities-ecomail-to-anabix.php --dry-run             (preview only)
 *   php activities-ecomail-to-anabix.php --full                (reset dedup)
 *   php activities-ecomail-to-anabix.php --campaign=3          (single campaign)
 *
 *   Browser: activities-ecomail-to-anabix.php?dry-run=1&campaign=3
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
    $onlyCampaign = null;
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, '--campaign=')) {
            $onlyCampaign = (int) substr($arg, 11);
        }
    }
} else {
    $dryRun = ($_GET['dry-run'] ?? '') === '1';
    $fullRun = ($_GET['full'] ?? '') === '1';
    $onlyCampaign = isset($_GET['campaign']) ? (int) $_GET['campaign'] : null;
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
    'click'          => 'clicked link in newsletter',
    'hard_bounce'    => 'note',
    'soft_bounce'    => 'note',
    'out_of_band'    => 'note',
    'unsub'          => 'note',
    'spam'           => 'note',
    'spam_complaint' => 'note',
];

// ── State (deduplication) ────────────────────────────────────────────

$stateDir = __DIR__ . '/storage/state';
if (!is_dir($stateDir)) {
    mkdir($stateDir, 0755, true);
}
$stateFile = $stateDir . '/activities-sync-state.json';

$state = [];
if (file_exists($stateFile)) {
    $state = json_decode(file_get_contents($stateFile), true) ?: [];
}

$processedKeys = ($fullRun) ? [] : ($state['processed_keys'] ?? []);

// ── Helper ────────────────────────────────────────────────────────────

function output(string $msg): void
{
    $time = date('H:i:s');
    echo "[{$time}] {$msg}" . PHP_EOL;
    if (php_sapi_name() !== 'cli') {
        flush();
    }
}

function saveState(string $stateFile, array $state): void
{
    file_put_contents(
        $stateFile,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

// ── Run sync ──────────────────────────────────────────────────────────

output("=== Ecomail activities → Anabix ===");
if ($dryRun) {
    output("DRY RUN — no changes will be made in Anabix.");
}
if ($fullRun) {
    output("Run: FULL (dedup reset, all events reprocessed)");
}
if ($onlyCampaign !== null) {
    output("Run: SINGLE CAMPAIGN #{$onlyCampaign}");
}

$report = [
    'status' => 'ok',
    'campaigns_processed' => 0,
    'campaigns_skipped' => 0,
    'subscribers_processed' => 0,
    'activities_created' => 0,
    'skipped_no_anabix_id' => 0,
    'skipped_duplicate' => 0,
    'skipped_unmapped' => 0,
    'failed' => 0,
    'errors' => [],
];

$runStartedAt = date('Y-m-d H:i:s');

try {
    // ── 1. Fetch campaigns list ───────────────────────────────────────

    output("Fetching campaigns from Ecomail...");
    $campaigns = $ecomail->getCampaigns('sent');

    if (empty($campaigns)) {
        output("No sent campaigns found.");
        goto finish;
    }

    output("Found " . count($campaigns) . " sent campaign(s).");

    // Filter to single campaign if requested
    if ($onlyCampaign !== null) {
        $campaigns = array_filter($campaigns, function ($c) use ($onlyCampaign) {
            $cid = (int) ($c['id'] ?? $c['campaign_id'] ?? 0);
            return $cid === $onlyCampaign;
        });
        if (empty($campaigns)) {
            output("Campaign #{$onlyCampaign} not found in sent list.");
            goto finish;
        }
    }

    // Cache for anabixId lookups across all campaigns
    $anabixIdCache = [];

    // ── 2. Process each campaign ──────────────────────────────────────

    foreach ($campaigns as $campaign) {
        $campaignId = (int) ($campaign['id'] ?? $campaign['campaign_id'] ?? 0);
        if ($campaignId === 0) {
            continue;
        }

        $subject = $campaign['subject'] ?? $campaign['title'] ?? '(no subject)';
        $title = $campaign['title'] ?? $subject;
        $fromName = $campaign['from_name'] ?? '';
        $fromEmail = $campaign['from_email'] ?? '';
        $sentAt = $campaign['date_sent']
            ?? $campaign['sent_at']
            ?? $campaign['send_at']
            ?? $campaign['created_at']
            ?? null;
        $archiveUrl = $campaign['archive_url'] ?? '';

        output("Campaign #{$campaignId}: {$subject}");

        // Fetch per-subscriber aggregated counts
        $subscribers = $ecomail->getCampaignStatsDetail($campaignId);

        if (empty($subscribers)) {
            output("  No subscribers in stats-detail.");
            $report['campaigns_processed']++;
            continue;
        }

        output("  Subscribers: " . count($subscribers));

        foreach ($subscribers as $email => $stats) {
            if (!is_array($stats) || $email === '') {
                continue;
            }
            $report['subscribers_processed']++;

            // Get anabixId (cached)
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
            $anabixId = (int) $anabixId;

            // For each event type with non-zero count → create activity
            foreach ($eventTypeMap as $eventType => $activityType) {
                $count = (int) ($stats[$eventType] ?? 0);
                if ($count === 0) {
                    continue;
                }

                // Dedup: campaign + contact + event type
                $dedupKey = md5("{$campaignId}|{$anabixId}|{$eventType}");
                if (isset($processedKeys[$dedupKey])) {
                    $report['skipped_duplicate']++;
                    continue;
                }

                // For open/click events show count even when it is 1.
                // For other events (send, bounces, unsub, spam) count is omitted.
                $showCount = in_array($eventType, ['open', 'click'], true);
                $statusLabel = $showCount
                    ? "{$eventType} ({$count}×)"
                    : $eventType;

                // Build activity title
                $activityTitle = "{$subject} - {$statusLabel}";

                // Build activity body
                $bodyLines = [];
                $bodyLines[] = "Kampaň: {$title} (viz Ecomail)";
                $bodyLines[] = "Stav: {$statusLabel}";
                $bodyLines[] = "Předmět: {$subject}";
                if ($fromName !== '' || $fromEmail !== '') {
                    $bodyLines[] = "Od: {$fromName} | {$fromEmail}";
                }
                if ($archiveUrl !== '') {
                    $bodyLines[] = $archiveUrl;
                }
                if ($sentAt !== null) {
                    $sentTs = is_numeric($sentAt) ? (int) $sentAt : strtotime((string) $sentAt);
                    if ($sentTs !== false && $sentTs > 0) {
                        $bodyLines[] = '';
                        $bodyLines[] = 'Datum odeslání kampaně: ' . date('d.m.Y', $sentTs);
                    }
                }
                $body = implode("\n", $bodyLines);

                if ($dryRun) {
                    output("  [DRY] {$email} (anabixId={$anabixId}): {$eventType}×{$count} → {$activityType}");
                    $report['activities_created']++;
                    $processedKeys[$dedupKey] = true;
                    continue;
                }

                // Create activity in Anabix
                $result = $anabix->createActivity(
                    $anabixId,
                    $activityTitle,
                    $body,
                    $activityType,
                    $sentAt,
                    $activityIdUser
                );

                if ($result !== null) {
                    $report['activities_created']++;
                    $processedKeys[$dedupKey] = true;

                    // Checkpoint every 50 successes
                    if ($report['activities_created'] % 50 === 0) {
                        $state['processed_keys'] = $processedKeys;
                        saveState($stateFile, $state);
                    }
                } else {
                    $report['failed']++;
                    $report['errors'][] = "Failed: campaign={$campaignId} email={$email} event={$eventType}";
                }

                usleep(150000);
            }
        }

        $report['campaigns_processed']++;

        // Save state after each campaign
        if (!$dryRun) {
            $state['processed_keys'] = $processedKeys;
            saveState($stateFile, $state);
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

// ── Save final state ─────────────────────────────────────────────────

if (!$dryRun) {
    $state['processed_keys'] = $processedKeys;
    $state['last_sync_started_at'] = $runStartedAt;
    $state['last_sync_finished_at'] = date('Y-m-d H:i:s');
    saveState($stateFile, $state);

    output("");
    output("Checkpoint saved: " . count($processedKeys) . " dedup keys stored");
}

// ── Summary ──────────────────────────────────────────────────────────

output("");
output("=== Summary ===");
output("Campaigns:        {$report['campaigns_processed']}");
output("Subscribers:      {$report['subscribers_processed']}");
output("Created:          {$report['activities_created']}");
output("No anabixId:      {$report['skipped_no_anabix_id']}");
output("Duplicates:       {$report['skipped_duplicate']}");
output("Unmapped:         {$report['skipped_unmapped']}");
output("Failed:           {$report['failed']}");
output("Status:           {$report['status']}");

$logger->info("Activities sync completed", $report);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
