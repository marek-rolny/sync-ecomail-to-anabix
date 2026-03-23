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

require_once __DIR__ . '/src/env.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/SyncState.php';
require_once __DIR__ . '/src/AnabixClient.php';
require_once __DIR__ . '/src/EcomailClient.php';
require_once __DIR__ . '/src/Transformer.php';

// ── Load configuration ────────────────────────────────────────────────

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Error: .env file not found. Copy .env.example to .env and configure it.\n");
    exit(1);
}
loadEnv($envFile);

// ── Validate required vars ────────────────────────────────────────────

$required = ['ANABIX_USERNAME', 'ANABIX_TOKEN', 'ANABIX_API_URL', 'ECOMAIL_API_KEY', 'ECOMAIL_LIST_ID'];
foreach ($required as $var) {
    if (env($var) === '') {
        fwrite(STDERR, "Error: {$var} is not set in .env\n");
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

$transformer = new Transformer($ownerMap, $customFieldMap, $birthdayFieldId);

// ── Runtime options ───────────────────────────────────────────────────

$fetchDetail = env('ANABIX_FETCH_DETAIL', 'false') === 'true';
$fetchLists = env('ANABIX_FETCH_LISTS', 'true') === 'true';
$fetchOrgs = env('ANABIX_FETCH_ORGS', 'true') === 'true';
$orgConcurrency = (int) env('ANABIX_ORG_CONCURRENCY', '20');
$orgCacheFile = env('ANABIX_ORG_CACHE_FILE', __DIR__ . '/storage/state/org_cache.json');
$batchSize = (int) env('ECOMAIL_BATCH_SIZE', '500');
$lookbackMinutes = (int) env('SYNC_LOOKBACK_MINUTES', '60');
$forceSince = env('SYNC_FORCE_SINCE', '') ?: null;

// ── Helper ────────────────────────────────────────────────────────────

function output(string $msg): void
{
    $time = date('H:i:s');
    echo "[{$time}] {$msg}" . PHP_EOL;
}

// ── Run sync ──────────────────────────────────────────────────────────

output("=== Anabix → Ecomail contact sync ===");

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

    $changedSince = $syncState->getChangedSince($forceSince, $lookbackMinutes);

    if ($changedSince !== null) {
        $report['sync_mode'] = 'delta';
        output("Delta sync: changes since {$changedSince}");
    } else {
        $report['sync_mode'] = 'full';
        output("Full sync (no previous state)");
    }

    $logger->info("Starting sync", [
        'mode' => $report['sync_mode'],
        'changedSince' => $changedSince,
    ]);

    // ── Step 2: Fetch contacts from Anabix ────────────────────────────

    output("Fetching contacts from Anabix...");
    $contacts = $anabix->getContacts($changedSince);

    // Fallback: delta returned 0 → try full export
    if (empty($contacts) && $changedSince !== null) {
        output("Delta returned 0 contacts, falling back to full export...");
        $logger->info("Delta empty, falling back to full export");
        $contacts = $anabix->getContacts(null);
        $report['sync_mode'] = 'full_fallback';
    }

    $report['contacts_fetched'] = count($contacts);
    output("Fetched: {$report['contacts_fetched']} contacts");

    if (empty($contacts)) {
        output("No contacts to sync.");
        $report['status'] = 'ok';
        goto finish;
    }

    // ── Step 3: Fetch list memberships (optional) ─────────────────────

    $listMemberMap = []; // contactId => [listTitle, ...]

    if ($fetchLists) {
        output("Fetching list memberships...");
        $lists = $anabix->getLists();
        $logger->info("Fetched lists", ['count' => count($lists)]);

        foreach ($lists as $list) {
            $listId = $list['idList'] ?? $list['id'] ?? null;
            $listTitle = $list['title'] ?? $list['name'] ?? '';
            if ($listId === null || $listTitle === '') {
                continue;
            }

            $members = $anabix->getListMembers((int) $listId);
            foreach ($members as $memberId) {
                $listMemberMap[$memberId][] = $listTitle;
            }

            usleep(200000); // rate limiting
        }

        output("List memberships loaded for " . count($listMemberMap) . " contacts");
    }

    // ── Step 4: Fetch organizations (optional, cached) ────────────────

    $orgCache = [];

    if ($fetchOrgs) {
        // Load existing cache
        if (file_exists($orgCacheFile)) {
            $orgCache = json_decode(file_get_contents($orgCacheFile), true) ?: [];
        }

        // Collect unique org IDs from contacts
        $neededOrgIds = [];
        foreach ($contacts as $c) {
            $orgId = $c['idOrganization'] ?? $c['organizationId'] ?? null;
            if ($orgId !== null && (int) $orgId > 0 && !isset($orgCache[(int) $orgId])) {
                $neededOrgIds[(int) $orgId] = true;
            }
        }
        $neededOrgIds = array_keys($neededOrgIds);

        if (!empty($neededOrgIds)) {
            output("Fetching " . count($neededOrgIds) . " organizations (parallel)...");
            $fetched = $anabix->getOrganizationsParallel($neededOrgIds, $orgConcurrency);

            // Merge into cache and save
            foreach ($fetched as $orgId => $orgData) {
                $orgCache[$orgId] = $orgData;
            }

            $cacheDir = dirname($orgCacheFile);
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }
            file_put_contents(
                $orgCacheFile,
                json_encode($orgCache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );

            output("Org cache updated: " . count($orgCache) . " total");
        } else {
            output("All organizations in cache (" . count($orgCache) . ")");
        }
    }

    // ── Step 5: Transform contacts ────────────────────────────────────

    output("Transforming contacts...");
    $subscribers = [];

    foreach ($contacts as $contact) {
        $contactId = $contact['idContact'] ?? $contact['id'] ?? null;

        // Optionally fetch full detail
        if ($fetchDetail && $contactId !== null) {
            $detail = $anabix->getContact((int) $contactId);
            if ($detail !== null) {
                $contact = array_merge($contact, $detail);
            }
            usleep(200000);
        }

        // Inject list memberships if not already present
        if ($contactId !== null && empty($contact['lists']) && isset($listMemberMap[(int) $contactId])) {
            $contact['lists'] = array_map(
                fn($title) => ['title' => $title],
                $listMemberMap[(int) $contactId]
            );
        }

        // Get organization from cache
        $orgId = $contact['idOrganization'] ?? $contact['organizationId'] ?? null;
        $org = ($orgId !== null && isset($orgCache[(int) $orgId])) ? $orgCache[(int) $orgId] : null;

        // Transform
        $subscriber = $transformer->transform($contact, $org);

        if ($subscriber === null) {
            $report['skipped']++;
            continue;
        }

        $subscribers[] = $subscriber;
    }

    $report['transformed'] = count($subscribers);
    output("Transformed: {$report['transformed']} subscribers (skipped: {$report['skipped']})");

    if (empty($subscribers)) {
        output("No valid subscribers to send.");
        goto finish;
    }

    // ── Step 6: Send to Ecomail in batches ────────────────────────────

    $batches = array_chunk($subscribers, $batchSize);
    output("Sending " . count($subscribers) . " subscribers in " . count($batches) . " batch(es)...");

    foreach ($batches as $i => $batch) {
        $batchNum = $i + 1;
        output("  Batch {$batchNum}/" . count($batches) . " (" . count($batch) . " subscribers)");

        $result = $ecomail->bulkUpsertContacts($batch);

        $report['imported'] += $result['imported'];
        $report['updated'] += $result['updated'];
        $report['failed'] += $result['failed'];

        foreach ($result['errors'] as $err) {
            $report['errors'][] = "Batch {$batchNum}: {$err}";
        }

        // Rate limiting between batches
        if ($batchNum < count($batches)) {
            sleep(2);
        }
    }

    output("Import done: imported={$report['imported']} updated={$report['updated']} failed={$report['failed']}");

    // ── Step 7: Save state ────────────────────────────────────────────

    // Only save state if no failures occurred
    if ($report['failed'] === 0) {
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
output("Mode:         {$report['sync_mode']}");
output("Fetched:      {$report['contacts_fetched']}");
output("Transformed:  {$report['transformed']}");
output("Skipped:      {$report['skipped']}");
output("Imported:     {$report['imported']}");
output("Updated:      {$report['updated']}");
output("Failed:       {$report['failed']}");
output("Status:       {$report['status']}");

if (!empty($report['errors'])) {
    output("");
    output("Errors:");
    foreach (array_slice($report['errors'], 0, 20) as $err) {
        output("  - {$err}");
    }
}

$logger->info("Sync completed", $report);

// JSON output (for automated processing / HTTP)
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
