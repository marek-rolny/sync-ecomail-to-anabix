<?php

/**
 * Sync contacts from Anabix CRM → Ecomail.
 *
 * Lifecycle:
 *  1. Determine sync window (delta or full)
 *  2. Fetch contacts from Anabix (paginated)
 *  3. Fallback: if delta returns 0, retry as full export
 *  4. Optionally fetch list memberships → inject as contact['lists']
 *  5. Optionally fetch organizations (parallel, cached)
 *  6. Transform each contact (Transformer)
 *  7. Send to Ecomail in batches (subscribe-bulk)
 *  8. Save sync state only on success
 *
 * Usage:
 *   php contacts-anabix-to-ecomail.php
 *
 * Can be run from cron or manually. Returns JSON report.
 */

// ── Web compatibility: prevent proxy timeout ─────────────────────────
set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');
ignore_user_abort(true); // Continue running even if browser/proxy disconnects

$isWeb = php_sapi_name() !== 'cli';

if ($isWeb) {
    // Send an immediate response to the browser, then continue sync in background.
    // This prevents proxy timeouts (sync takes ~60-70s which exceeds most proxy limits).

    $webStatusFile = __DIR__ . '/storage/logs/last_run_status.json';
    $lastStatus = file_exists($webStatusFile) ? json_decode(file_get_contents($webStatusFile), true) : null;

    $mode = $_GET['mode'] ?? 'delta';
    $body = "Sync started (mode={$mode}). The sync runs in the background (~60-70s).\n\n";
    if ($lastStatus) {
        $body .= "Last run: {$lastStatus['timestamp']}\n";
        $body .= "  Mode:      {$lastStatus['mode']}\n";
        $body .= "  Fetched:   {$lastStatus['fetched']}\n";
        $body .= "  Emails:    {$lastStatus['unique_emails']}\n";
        $body .= "  Status:    {$lastStatus['status']}\n";
    } else {
        $body .= "No previous run found.\n";
    }
    $body .= "\nReload this page in ~70s to see updated results.\n";

    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache');
    header('Connection: close');
    header('Content-Length: ' . strlen($body));
    echo $body;

    // Flush everything to the web server
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level()) {
            ob_end_flush();
        }
        flush();
    }

    // Script continues running in the background from here
}

require_once __DIR__ . '/src/env.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/SyncState.php';
require_once __DIR__ . '/src/AnabixClient.php';
require_once __DIR__ . '/src/EcomailClient.php';
require_once __DIR__ . '/src/Transformer.php';

// ── Prevent concurrent runs ──────────────────────────────────────────
$lockFile = __DIR__ . '/storage/state/sync.lock';
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) {
    mkdir($lockDir, 0755, true);
}
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    $msg = 'Another sync is already running. Please wait for it to finish.';
    if (php_sapi_name() !== 'cli') {
        echo $msg . "\n";
    } else {
        fwrite(STDERR, $msg . "\n");
    }
    fclose($lockFp);
    exit(0);
}
// Lock acquired — will be released when the script ends (or on fclose)
fwrite($lockFp, (string) getmypid());
fflush($lockFp);

// ── Load configuration ────────────────────────────────────────────────

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    echo "Error: .env file not found. Copy .env.example to .env and configure it.\n";
    exit(1);
}
loadEnv($envFile);

// ── Validate required vars ────────────────────────────────────────────

$required = ['ANABIX_USERNAME', 'ANABIX_TOKEN', 'ANABIX_API_URL', 'ECOMAIL_API_KEY', 'ECOMAIL_LIST_ID'];
foreach ($required as $var) {
    if (env($var) === '') {
        echo "Error: {$var} is not set in .env\n";
        exit(1);
    }
}

// ── Initialize components ─────────────────────────────────────────────

$logger = new Logger(__DIR__ . '/storage/logs');

$stateFile = env('SYNC_STATE_FILE', __DIR__ . '/storage/state/last_sync.json');
$syncState = new SyncState($stateFile);

$anabix = new AnabixClient(
    env('ANABIX_USERNAME'),
    env('ANABIX_TOKEN'),
    env('ANABIX_API_URL'),
    $logger
);

$ecomail = new EcomailClient(
    env('ECOMAIL_API_KEY'),
    env('ECOMAIL_API_URL', 'https://api2.ecomailapp.cz'),
    (int) env('ECOMAIL_LIST_ID'),
    $logger,
    env('ECOMAIL_TRIGGER_AUTORESPONDERS', 'false') === 'true',
    env('ECOMAIL_RESUBSCRIBE', 'false') === 'true'
);

// ── Build owner map from ANABIX_OWNER_* env vars ──────────────────────

$ownerMap = [];
foreach ($_ENV as $key => $value) {
    if (str_starts_with($key, 'ANABIX_OWNER_')) {
        $id = (int) substr($key, strlen('ANABIX_OWNER_'));
        if ($id > 0 && $value !== '') {
            $ownerMap[$id] = $value;
        }
    }
}

// ── Build custom field map from ANABIX_CF_* env vars ──────────────────

$customFieldMap = [];
foreach ($_ENV as $key => $value) {
    if (str_starts_with($key, 'ANABIX_CF_')) {
        // Key after ANABIX_CF_ is the Ecomail merge tag name (case preserved)
        $ecomailKey = substr($key, strlen('ANABIX_CF_'));
        $anabixFieldId = (int) $value;
        if ($ecomailKey !== '' && $anabixFieldId > 0) {
            $customFieldMap[$ecomailKey] = $anabixFieldId;
        }
    }
}

$birthdayFieldId = env('ANABIX_BIRTHDAY_FIELD_ID', '') !== ''
    ? (int) env('ANABIX_BIRTHDAY_FIELD_ID')
    : null;

// ── Runtime options ───────────────────────────────────────────────────

$fetchDetail = env('ANABIX_FETCH_DETAIL', 'false') === 'true';
$fetchLists = env('ANABIX_FETCH_LISTS', 'false') === 'true';
$fetchOrgs = env('ANABIX_FETCH_ORGS', 'true') === 'true';
$orgConcurrency = (int) env('ANABIX_ORG_CONCURRENCY', '20');
$orgCacheFile = env('ANABIX_ORG_CACHE_FILE', __DIR__ . '/storage/state/org_cache.json');
$batchSize = (int) env('ECOMAIL_BATCH_SIZE', '500');
$lookbackMinutes = (int) env('SYNC_LOOKBACK_MINUTES', '60');
$forceSince = env('SYNC_FORCE_SINCE', '') ?: null;

// Default owner: ANABIX_OWNER_6 ("Robot Karel") — used when contact's idOwner is not in the map
$defaultOwner = $ownerMap[6] ?? 'Robot Karel';

$transformer = new Transformer($ownerMap, $customFieldMap, $birthdayFieldId, $defaultOwner, $fetchLists);

// ── Helpers ───────────────────────────────────────────────────────────

function output(string $msg): void
{
    // In web mode, the connection is already closed — only log to stdout in CLI
    if (php_sapi_name() === 'cli') {
        $time = date('H:i:s');
        echo "[{$time}] {$msg}" . PHP_EOL;
    }
}

/**
 * Process contact pages from a generator: transform and send to Ecomail in batches.
 *
 * Deduplicates by email — each email is sent only once.
 * Modifies $report, $orgCache, $subscribers, $batchNum, $seenEmails by reference.
 * Returns true if any contacts were processed.
 */
function processContactPages(
    \Generator $pages,
    array &$report,
    array &$subscribers,
    int &$batchNum,
    array &$orgCache,
    array &$seenEmails,
    AnabixClient $anabix,
    EcomailClient $ecomail,
    Transformer $transformer,
    Logger $logger,
    bool $fetchOrgs,
    bool $fetchDetail,
    int $orgConcurrency,
    int $batchSize
): bool {
    $hasContacts = false;
    $debugDone = false;
    $dumpCount = 0;

    $pageNum = 0;
    foreach ($pages as $pageContacts) {
        $pageNum++;
        $hasContacts = true;
        $report['contacts_fetched'] += count($pageContacts);

        // Debug: show page info and first few contact IDs
        $ids = array_map(fn($c) => $c['idContact'] ?? $c['id'] ?? '?', array_slice($pageContacts, 0, 5));
        output("  Page {$pageNum}: " . count($pageContacts) . " contacts, first IDs: " . implode(',', $ids));

        // Debug: log structure of first contact
        if (!$debugDone && !empty($pageContacts)) {
            $first = reset($pageContacts);
            $cfInfo = isset($first['customFields']) ? count($first['customFields']) . ' custom fields' : 'no customFields';
            output("  Debug first contact: {$cfInfo}, keys: " . implode(',', array_keys($first)));
            $debugDone = true;
        }

        // Fetch missing organizations for this page
        if ($fetchOrgs) {
            $neededOrgIds = [];
            foreach ($pageContacts as $c) {
                $orgId = $c['idOrganization'] ?? $c['organizationId'] ?? null;
                if ($orgId !== null && (int) $orgId > 0 && !isset($orgCache[(int) $orgId])) {
                    $neededOrgIds[(int) $orgId] = true;
                }
            }
            $neededOrgIds = array_keys($neededOrgIds);

            if (!empty($neededOrgIds)) {
                $logger->info("Fetching organizations for page {$pageNum}", ['count' => count($neededOrgIds)]);
                $fetched = $anabix->getOrganizationsParallel($neededOrgIds, $orgConcurrency);
                foreach ($fetched as $orgId => $orgData) {
                    $orgCache[$orgId] = $orgData;
                }
                $logger->info("Organizations fetched for page {$pageNum}", ['fetched' => count($fetched)]);
            }
        }

        // Transform contacts from this page
        foreach ($pageContacts as $contact) {
            $contactId = $contact['idContact'] ?? $contact['id'] ?? null;
            $rawEmail = $contact['email'] ?? '';

            // Count contacts without email
            if (trim($rawEmail) === '') {
                $report['skipped_no_email'] = ($report['skipped_no_email'] ?? 0) + 1;
                $report['skipped']++;
                continue;
            }

            if ($fetchDetail && $contactId !== null) {
                $detail = $anabix->getContact((int) $contactId);
                if ($detail !== null) {
                    $contact = array_merge($contact, $detail);
                }
                usleep(200000);
            }

            $orgId = $contact['idOrganization'] ?? $contact['organizationId'] ?? null;
            $org = ($orgId !== null && isset($orgCache[(int) $orgId])) ? $orgCache[(int) $orgId] : null;

            $subscriber = $transformer->transform($contact, $org);

            if ($subscriber === null) {
                $report['skipped_invalid_email'] = ($report['skipped_invalid_email'] ?? 0) + 1;
                $report['skipped']++;
                continue;
            }

            // Deduplicate by email — keep only first occurrence
            $email = $subscriber['email'];
            if (isset($seenEmails[$email])) {
                $report['skipped_duplicate'] = ($report['skipped_duplicate'] ?? 0) + 1;
                $report['skipped']++;
                continue;
            }
            $seenEmails[$email] = true;

            // Dump first 10 transformed contacts to log
            if ($dumpCount < 10) {
                $logger->debug("Sample contact #{$dumpCount}", [
                    'anabix_id' => $contactId,
                    'subscriber' => $subscriber,
                ]);
                $dumpCount++;
            }

            $subscribers[] = $subscriber;

            // Send batch when we have enough subscribers
            if (count($subscribers) >= $batchSize) {
                $batchNum++;
                $report['transformed'] += count($subscribers);
                $logger->info("Sending Ecomail batch {$batchNum}", ['count' => count($subscribers)]);
                output("  Batch {$batchNum} (" . count($subscribers) . " subscribers)");

                $result = $ecomail->bulkUpsertContacts($subscribers);
                $report['imported'] += $result['imported'];
                $report['updated'] += $result['updated'];
                $report['failed'] += $result['failed'];
                foreach ($result['errors'] as $err) {
                    $report['errors'][] = "Batch {$batchNum}: {$err}";
                }

                // Show Ecomail raw response for debugging
                if (isset($result['ecomail_response'])) {
                    output("  Ecomail response: " . json_encode($result['ecomail_response'], JSON_UNESCAPED_UNICODE));
                }

                $subscribers = []; // free memory
                sleep(2); // rate limiting
            }
        }

        $logger->info("Processed page {$pageNum}", [
            'total_fetched' => $report['contacts_fetched'],
            'unique_emails' => count($seenEmails),
            'buffer' => count($subscribers),
        ]);
        output("Processed page — total fetched: {$report['contacts_fetched']}, unique emails: " . count($seenEmails));
    }

    return $hasContacts;
}

// ── Run sync ──────────────────────────────────────────────────────────

output("=== Anabix → Ecomail contact sync (v2026-03-24c) ===");

$report = [
    'status' => 'ok',
    'sync_mode' => 'unknown',
    'contacts_fetched' => 0,
    'transformed' => 0,
    'skipped' => 0,
    'imported' => 0,
    'updated' => 0,
    'failed' => 0,
    'errors' => [],
];

try {
    // ── Step 1: Determine sync window ─────────────────────────────────
    // Support ?mode=full in URL to force full sync from browser
    $urlMode = $_GET['mode'] ?? '';
    if ($urlMode === 'full') {
        // Anabix API requires changedSince for proper pagination.
        // Use a very old date to get all contacts.
        $changedSince = '2000-01-01T00:00:00+00:00';
        $report['sync_mode'] = 'full';
        output("Full sync forced via ?mode=full (changedSince={$changedSince})");
    } else {
        $changedSince = $syncState->getChangedSince($forceSince, $lookbackMinutes);
    }

    if ($changedSince === null) {
        // No previous state — also use old date for same reason
        $changedSince = '2000-01-01T00:00:00+00:00';
        $report['sync_mode'] = 'full';
        output("Full sync (no previous state, changedSince={$changedSince})");
    } elseif ($report['sync_mode'] !== 'full') {
        $report['sync_mode'] = 'delta';
        output("Delta sync: changes since {$changedSince}");
    }

    $logger->info("Starting sync", [
        'mode' => $report['sync_mode'],
        'changedSince' => $changedSince,
    ]);

    // ── Step 2: Load org cache (optional) ───────────────────────────────

    $orgCache = [];

    if ($fetchOrgs && file_exists($orgCacheFile)) {
        $orgCache = json_decode(file_get_contents($orgCacheFile), true) ?: [];
        output("Org cache loaded: " . count($orgCache) . " entries");
    }

    // ── Step 3: Fetch & process contacts page-by-page ─────────────────
    //
    // Instead of loading all contacts into memory at once, we process
    // them in pages: fetch page → fetch missing orgs → transform → send batch.
    // When fetchLists is enabled, we request fullInfo so contact lists
    // (used as Ecomail tags) are included in the getAll response.

    // Always use fullInfo=1 to get customFields and lists data from Anabix.
    // $fetchLists controls only whether list names become Ecomail tags.
    output("Fetching contacts from Anabix (fullInfo)...");

    $subscribers = [];
    $batchNum = 0;
    $seenEmails = [];  // deduplicate across all pages

    $hasContacts = processContactPages(
        $anabix->getContactsPaginated($changedSince, true),
        $report, $subscribers, $batchNum, $orgCache, $seenEmails,
        $anabix, $ecomail, $transformer, $logger,
        $fetchOrgs, $fetchDetail, $orgConcurrency, $batchSize
    );

    // Fallback: delta returned 0 → try full export with old date
    if (!$hasContacts) {
        $fallbackSince = '2000-01-01T00:00:00+00:00';
        output("Delta returned 0 contacts, falling back to full export (changedSince={$fallbackSince})...");
        $logger->info("Delta empty, falling back to full export");
        $report['sync_mode'] = 'full_fallback';

        $hasContacts = processContactPages(
            $anabix->getContactsPaginated($fallbackSince, true),
            $report, $subscribers, $batchNum, $orgCache, $seenEmails,
            $anabix, $ecomail, $transformer, $logger,
            $fetchOrgs, $fetchDetail, $orgConcurrency, $batchSize
        );
    }

    // Send remaining subscribers
    if (!empty($subscribers)) {
        $batchNum++;
        $report['transformed'] += count($subscribers);
        $logger->info("Sending final Ecomail batch {$batchNum}", ['count' => count($subscribers)]);
        output("  Batch {$batchNum} (" . count($subscribers) . " subscribers)");

        $result = $ecomail->bulkUpsertContacts($subscribers);
        $report['imported'] += $result['imported'];
        $report['updated'] += $result['updated'];
        $report['failed'] += $result['failed'];
        foreach ($result['errors'] as $err) {
            $report['errors'][] = "Batch {$batchNum}: {$err}";
        }

        // Show Ecomail raw response for debugging
        if (isset($result['ecomail_response'])) {
            output("  Ecomail response: " . json_encode($result['ecomail_response'], JSON_UNESCAPED_UNICODE));
        }

        $subscribers = [];
    }

    $uniqueEmails = count($seenEmails);
    $noEmail = $report['skipped_no_email'] ?? 0;
    $invalidEmail = $report['skipped_invalid_email'] ?? 0;
    $duplicates = $report['skipped_duplicate'] ?? 0;

    output("Fetched: {$report['contacts_fetched']} contacts total from Anabix");
    output("Unique valid emails: {$uniqueEmails}");
    output("Skipped: {$report['skipped']} (no email: {$noEmail}, invalid email: {$invalidEmail}, duplicate: {$duplicates})");
    output("Transformed: {$report['transformed']} subscribers");
    output("Sent in {$batchNum} batch(es)");

    // Save updated org cache
    if ($fetchOrgs && !empty($orgCache)) {
        $cacheDir = dirname($orgCacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents(
            $orgCacheFile,
            json_encode($orgCache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
        output("Org cache saved: " . count($orgCache) . " total");
    }

    if (!$hasContacts) {
        output("No contacts to sync.");
        $report['status'] = 'ok';
        goto finish;
    }

    output("Import done: imported={$report['imported']} updated={$report['updated']} failed={$report['failed']}");

    // ── Step 7: Save state ────────────────────────────────────────────

    // Only save state if Ecomail confirmed imports/updates and no failures
    $totalProcessed = $report['imported'] + $report['updated'];
    if ($report['failed'] === 0 && $totalProcessed > 0) {
        $syncState->markCompleted();
        $syncState->save();
        output("Sync state saved.");
    } else {
        output("WARNING: {$report['failed']} failures — sync state NOT updated (will retry next run).");
        $report['status'] = 'partial';
    }

} catch (Throwable $e) {
    $report['status'] = 'error';
    $report['errors'][] = $e->getMessage();
    output("FATAL ERROR: " . $e->getMessage());
    $logger->error("Sync failed", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}

finish:

// ── Summary ──────────────────────────────────────────────────────────

output("");
output("=== Summary ===");
output("Mode:           {$report['sync_mode']}");
output("Fetched:        {$report['contacts_fetched']}");
output("Unique emails:  " . ($report['unique_emails'] ?? count($seenEmails ?? [])));
output("Transformed:    {$report['transformed']}");
output("Skipped total:  {$report['skipped']}");
output("  No email:     " . ($report['skipped_no_email'] ?? 0));
output("  Invalid email:" . ($report['skipped_invalid_email'] ?? 0));
output("  Duplicate:    " . ($report['skipped_duplicate'] ?? 0));
output("Imported:       {$report['imported']}");
output("Updated:        {$report['updated']}");
output("Failed:         {$report['failed']}");
output("Status:         {$report['status']}");

if (!empty($report['errors'])) {
    output("");
    output("Errors:");
    foreach (array_slice($report['errors'], 0, 20) as $err) {
        output("  - {$err}");
    }
}

// ── Save status for web display ──────────────────────────────────

$statusFile = __DIR__ . '/storage/logs/last_run_status.json';
$statusDir = dirname($statusFile);
if (!is_dir($statusDir)) {
    mkdir($statusDir, 0755, true);
}

$statusData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'mode' => $report['sync_mode'],
    'fetched' => $report['contacts_fetched'],
    'unique_emails' => count($seenEmails ?? []),
    'transformed' => $report['transformed'],
    'skipped' => $report['skipped'],
    'skipped_no_email' => $report['skipped_no_email'] ?? 0,
    'skipped_invalid_email' => $report['skipped_invalid_email'] ?? 0,
    'skipped_duplicate' => $report['skipped_duplicate'] ?? 0,
    'imported' => $report['imported'],
    'updated' => $report['updated'],
    'failed' => $report['failed'],
    'status' => $report['status'],
];

file_put_contents($statusFile, json_encode($statusData, JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX);

$logger->info("Sync completed", $report);

// JSON output (CLI only — in web mode the connection is already closed)
if (!$isWeb) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
