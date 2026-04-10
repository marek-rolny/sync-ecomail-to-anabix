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
require_once __DIR__ . '/src/Normalizer.php';
require_once __DIR__ . '/src/CheckpointManager.php';

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

$fetchOrgs = env('ANABIX_FETCH_ORGS', 'true') === 'true';
$orgConcurrency = (int) env('ANABIX_ORG_CONCURRENCY', '20');
$orgCacheFile = env('ANABIX_ORG_CACHE_FILE', __DIR__ . '/storage/state/org_cache.json');
$batchSize = (int) env('ECOMAIL_BATCH_SIZE', '500');
$lookbackMinutes = (int) env('SYNC_LOOKBACK_MINUTES', '60');
$forceSince = env('SYNC_FORCE_SINCE', '') ?: null;

// Default owner: ANABIX_OWNER_6 ("Robot Karel") — used when contact's idOwner is not in the map
$defaultOwner = $ownerMap[6] ?? 'Robot Karel';

// ── Trigger tags for Ecomail automation ──────────────────────────────
// Tags that should fire "Kontakt dostane štítek" automations.
// subscribe-bulk cannot trigger these; a separate PUT update-subscriber call is needed.

$triggerTagsRaw = env('ECOMAIL_TRIGGER_TAGS', '');
$triggerTags = $triggerTagsRaw !== ''
    ? array_values(array_filter(array_map('trim', explode(',', $triggerTagsRaw))))
    : [];
$tagCacheFile = __DIR__ . '/storage/state/tag_cache.json';

$transformer = new Transformer($ownerMap, $customFieldMap, $birthdayFieldId, $defaultOwner);

$checkpoint = new CheckpointManager(__DIR__ . '/storage/state');

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
    int $orgConcurrency,
    int $batchSize,
    ?int &$maxTimestamp = null,
    ?CheckpointManager $checkpoint = null,
    ?int $resumeFromOffset = null,
    array $triggerTags = [],
    string $tagCacheFile = ''
): bool {
    $hasContacts = false;
    $debugDone = false;
    $dumpCount = 0;
    $resumeSkipped = 0;

    $pageNum = 0;
    foreach ($pages as $pageContacts) {
        $pageNum++;
        $hasContacts = true;
        $report['contacts_fetched'] += count($pageContacts);

        // Resume logic: skip pages we already processed (based on checkpoint offset)
        if ($resumeFromOffset !== null && $report['contacts_fetched'] <= $resumeFromOffset) {
            $resumeSkipped += count($pageContacts);
            // Still track emails for dedup even when skipping
            foreach ($pageContacts as $c) {
                $resolved = $transformer->resolveEmail($c);
                if ($resolved !== null) {
                    $cId = $c['idContact'] ?? $c['id'] ?? null;
                    $seenEmails[$resolved['email']] = $cId;
                }
            }
            $logger->debug("Skipping page {$pageNum} (checkpoint resume)", [
                'fetched' => $report['contacts_fetched'],
                'resume_offset' => $resumeFromOffset,
            ]);
            continue;
        }
        if ($resumeSkipped > 0) {
            $logger->info("Resumed after checkpoint", [
                'skipped_contacts' => $resumeSkipped,
                'continuing_from' => $report['contacts_fetched'] - count($pageContacts),
            ]);
            output("Resumed: skipped {$resumeSkipped} already-processed contacts");
            $resumeSkipped = 0; // only log once
            $resumeFromOffset = null;
        }

        // Debug: show page info and first few contact IDs
        $ids = array_map(fn($c) => $c['idContact'] ?? $c['id'] ?? '?', array_slice($pageContacts, 0, 5));
        output("  Page {$pageNum}: " . count($pageContacts) . " contacts, first IDs: " . implode(',', $ids));

        // Debug: log structure of first contact
        if (!$debugDone && !empty($pageContacts)) {
            $first = reset($pageContacts);
            $cfInfo = isset($first['customFields']) ? count($first['customFields']) . ' custom fields' : 'no customFields';
            $revInfo = $first['revisionInfo'] ?? null;
            $revKeys = is_array($revInfo) ? implode(',', array_keys($revInfo)) : 'missing';
            output("  Debug first contact: {$cfInfo}, revisionInfo keys: [{$revKeys}]");
            $logger->info("First contact structure", [
                'keys' => array_keys($first),
                'revisionInfo' => $revInfo,
            ]);
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
            $contactName = trim(($contact['firstName'] ?? '') . ' ' . ($contact['lastName'] ?? ''));

            // Resolve best email (email → email2 → email3)
            $resolved = $transformer->resolveEmail($contact);

            if ($resolved === null) {
                // No valid email in any field
                $hasAnyEmail = trim($contact['email'] ?? '') !== ''
                    || trim($contact['email2'] ?? '') !== ''
                    || trim($contact['email3'] ?? '') !== '';
                if ($hasAnyEmail) {
                    $report['skipped_invalid_email'] = ($report['skipped_invalid_email'] ?? 0) + 1;
                    $report['skipped']++;
                    $report['skipped_contacts'][] = [
                        'id' => $contactId, 'name' => $contactName,
                        'email' => $contact['email'] ?? '', 'email2' => $contact['email2'] ?? '',
                        'email3' => $contact['email3'] ?? '', 'reason' => 'invalid_email',
                    ];
                } else {
                    $report['skipped_no_email'] = ($report['skipped_no_email'] ?? 0) + 1;
                    $report['skipped']++;
                    $report['skipped_contacts'][] = [
                        'id' => $contactId, 'name' => $contactName, 'reason' => 'no_email',
                    ];
                }
                continue;
            }

            // Track how many contacts used email2/email3 fallback
            if ($resolved['field'] !== 'email') {
                $report['email_fallback'] = ($report['email_fallback'] ?? 0) + 1;
                $logger->debug("Using fallback email field", [
                    'contact_id' => $contactId, 'name' => $contactName,
                    'field' => $resolved['field'], 'email' => $resolved['email'],
                ]);
            }

            $orgId = $contact['idOrganization'] ?? $contact['organizationId'] ?? null;
            $org = ($orgId !== null && isset($orgCache[(int) $orgId])) ? $orgCache[(int) $orgId] : null;

            $subscriber = $transformer->transform($contact, $org);

            if ($subscriber === null) {
                // Should not happen since resolveEmail already validated, but safety check
                $report['skipped_invalid_email'] = ($report['skipped_invalid_email'] ?? 0) + 1;
                $report['skipped']++;
                $report['skipped_contacts'][] = [
                    'id' => $contactId, 'name' => $contactName,
                    'email' => $contact['email'] ?? '', 'reason' => 'invalid_email',
                ];
                continue;
            }

            // Deduplicate by email — keep only first occurrence, log conflict
            $email = $subscriber['email'];
            if (isset($seenEmails[$email])) {
                $report['skipped_duplicate'] = ($report['skipped_duplicate'] ?? 0) + 1;
                $report['skipped']++;
                $report['skipped_contacts'][] = [
                    'id' => $contactId, 'name' => $contactName,
                    'email' => $email, 'reason' => 'duplicate',
                ];
                $logger->info("Duplicate contact skipped", [
                    'email' => $email,
                    'kept_anabix_id' => $seenEmails[$email],
                    'skipped_anabix_id' => $contactId,
                    'skipped_name' => $contactName,
                ]);
                continue;
            }
            $seenEmails[$email] = $contactId;

            // Track latest contact timestamp for cursor-based pagination.
            // revisionInfo.updatedTimestamp is a Unix timestamp (integer).
            $revInfo = $contact['revisionInfo'] ?? [];
            $contactTs = is_array($revInfo) ? ($revInfo['updatedTimestamp'] ?? $revInfo['createdTimestamp'] ?? null) : null;
            if ($contactTs !== null) {
                $contactTs = (int) $contactTs;
                if ($maxTimestamp === null || $contactTs > $maxTimestamp) {
                    $maxTimestamp = $contactTs;
                }
            }

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

                // Save checkpoint after successful batch
                if ($checkpoint !== null) {
                    $checkpoint->save('contacts-sync', [
                        'offset' => $report['contacts_fetched'],
                        'batch_num' => $batchNum,
                        'unique_emails' => count($seenEmails),
                        'imported' => $report['imported'],
                        'updated' => $report['updated'],
                        'failed' => $report['failed'],
                    ]);
                }

                // Fire separate PUT calls for contacts with newly-added trigger tags
                // so Ecomail "Kontakt dostane štítek" automations are triggered.
                if ($result['failed'] === 0 && !empty($triggerTags) && $tagCacheFile !== '') {
                    $ecomail->processTriggerTagUpdates($subscribers, $triggerTags, $tagCacheFile);
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

output("=== Anabix → Ecomail contact sync (v2026-03-25a) ===");

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
    'skipped_contacts' => [],
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
    // fullInfo=1 returns customFields, lists, and revisionInfo for each contact.
    output("Fetching contacts from Anabix (fullInfo)...");

    $subscribers = [];
    $batchNum = 0;
    $seenEmails = [];  // deduplicate across all pages — maps email => anabixId (for conflict logging)
    $resumeFromOffset = null;

    // Check for existing checkpoint (resume after crash)
    $existingCheckpoint = $checkpoint->load('contacts-sync');
    if ($existingCheckpoint !== null) {
        $resumeFromOffset = $existingCheckpoint['offset'] ?? null;
        $batchNum = $existingCheckpoint['batch_num'] ?? 0;
        $report['imported'] = $existingCheckpoint['imported'] ?? 0;
        $report['updated'] = $existingCheckpoint['updated'] ?? 0;
        $report['failed'] = $existingCheckpoint['failed'] ?? 0;
        $logger->info("Resuming from checkpoint", $existingCheckpoint);
        output("Resuming from checkpoint: offset={$resumeFromOffset}, batch={$batchNum}");
    }

    // Single-pass pagination: AnabixClient handles adaptive page sizing
    // (reduces page size on HTTP 500 to fetch remaining contacts).
    $maxTimestamp = null;
    $hasContacts = false;

    $hasContacts = processContactPages(
        $anabix->getContactsPaginated($changedSince, true),
        $report, $subscribers, $batchNum, $orgCache, $seenEmails,
        $anabix, $ecomail, $transformer, $logger,
        $fetchOrgs, $orgConcurrency, $batchSize,
        $maxTimestamp, $checkpoint, $resumeFromOffset,
        $triggerTags, $tagCacheFile
    );

    $logger->info("Fetch complete", [
        'total_fetched' => $report['contacts_fetched'],
        'unique_emails' => count($seenEmails),
    ]);

    // Fallback: delta returned 0 → try full export with old date
    if (!$hasContacts && $report['sync_mode'] === 'delta') {
        $fallbackSince = '2000-01-01T00:00:00+00:00';
        output("Delta returned 0 contacts, falling back to full export (changedSince={$fallbackSince})...");
        $logger->info("Delta empty, falling back to full export");
        $report['sync_mode'] = 'full_fallback';

        $hasContacts = processContactPages(
            $anabix->getContactsPaginated($fallbackSince, true),
            $report, $subscribers, $batchNum, $orgCache, $seenEmails,
            $anabix, $ecomail, $transformer, $logger,
            $fetchOrgs, $orgConcurrency, $batchSize,
            $maxTimestamp, $checkpoint, null,
            $triggerTags, $tagCacheFile
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

        // Fire separate PUT calls for contacts with newly-added trigger tags
        if ($result['failed'] === 0 && !empty($triggerTags)) {
            $ecomail->processTriggerTagUpdates($subscribers, $triggerTags, $tagCacheFile);
        }

        $subscribers = [];
    }

    $uniqueEmails = count($seenEmails);
    $noEmail = $report['skipped_no_email'] ?? 0;
    $invalidEmail = $report['skipped_invalid_email'] ?? 0;
    $duplicates = $report['skipped_duplicate'] ?? 0;

    output("Fetched: {$report['contacts_fetched']} contacts total from Anabix");
    output("Unique valid emails: {$uniqueEmails}");
    $emailFallback = $report['email_fallback'] ?? 0;
    output("Skipped: {$report['skipped']} (no email: {$noEmail}, invalid email: {$invalidEmail}, duplicate: {$duplicates})");
    output("Email fallback (email2/email3): {$emailFallback}");
    output("Transformed: {$report['transformed']} subscribers");
    output("Sent in {$batchNum} batch(es)");

    // Log skipped contacts list for review
    if (!empty($report['skipped_contacts'])) {
        $logger->info("Skipped contacts detail", [
            'count' => count($report['skipped_contacts']),
            'contacts' => $report['skipped_contacts'],
        ]);
    }

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

    // ── Step 5: GDPR cleanup — delete contacts not in Anabix (full sync only) ──

    $isFullSync = in_array($report['sync_mode'], ['full', 'full_fallback'], true);
    $gdprSafetyThreshold = 0.10; // max 10% deletion — safety brake

    if ($isFullSync && $report['failed'] === 0 && count($seenEmails) > 0) {
        output("");
        output("=== GDPR cleanup (full sync) ===");

        $ecomailEmails = $ecomail->getAllSubscriberEmails();
        output("Ecomail subscribers: " . count($ecomailEmails));

        if (empty($ecomailEmails)) {
            output("WARNING: Could not fetch Ecomail subscribers — skipping cleanup.");
            $logger->warning("GDPR cleanup skipped: failed to fetch Ecomail subscribers");
        } else {
            // Find emails in Ecomail that are NOT in Anabix
            $toDelete = array_diff($ecomailEmails, array_keys($seenEmails));
            $deleteCount = count($toDelete);
            $deleteRatio = $deleteCount / count($ecomailEmails);

            $report['gdpr_ecomail_total'] = count($ecomailEmails);
            $report['gdpr_to_delete'] = $deleteCount;

            $logger->info("GDPR cleanup candidates", [
                'ecomail_total' => count($ecomailEmails),
                'anabix_total' => count($seenEmails),
                'to_delete' => $deleteCount,
                'delete_ratio' => round($deleteRatio * 100, 1) . '%',
                'threshold' => round($gdprSafetyThreshold * 100) . '%',
            ]);

            if ($deleteCount === 0) {
                output("No contacts to delete — Ecomail is in sync with Anabix.");
            } elseif ($deleteRatio > $gdprSafetyThreshold) {
                output("WARNING: {$deleteCount} contacts to delete (" . round($deleteRatio * 100, 1) . "%) exceeds safety threshold (" . round($gdprSafetyThreshold * 100) . "%).");
                output("Cleanup SKIPPED — check logs and run again if this is expected.");
                $logger->warning("GDPR cleanup aborted: ratio exceeds threshold", [
                    'to_delete' => $deleteCount,
                    'ratio' => round($deleteRatio * 100, 1) . '%',
                    'emails_sample' => array_slice(array_values($toDelete), 0, 20),
                ]);
                $report['gdpr_status'] = 'skipped_threshold';
            } else {
                output("Deleting {$deleteCount} contacts not found in Anabix...");

                // Log full list before deletion
                $logger->info("GDPR deleting contacts", [
                    'count' => $deleteCount,
                    'emails' => array_values($toDelete),
                ]);

                $deleted = 0;
                $deleteFailed = 0;
                foreach ($toDelete as $email) {
                    if ($ecomail->deleteSubscriber($email)) {
                        $deleted++;
                        $logger->debug("GDPR deleted", ['email' => $email]);
                    } else {
                        $deleteFailed++;
                        $logger->error("GDPR delete failed", ['email' => $email]);
                    }
                    usleep(200000); // 200ms between deletes — rate limit courtesy
                }

                output("GDPR cleanup done: deleted={$deleted}, failed={$deleteFailed}");
                $report['gdpr_deleted'] = $deleted;
                $report['gdpr_delete_failed'] = $deleteFailed;
                $report['gdpr_status'] = 'completed';
            }
        }
    } elseif (!$isFullSync) {
        $logger->debug("GDPR cleanup skipped: not a full sync", ['mode' => $report['sync_mode']]);
    }

    // ── Step 7: Save state ────────────────────────────────────────────

    // Only save state if Ecomail confirmed imports/updates and no failures
    $totalProcessed = $report['imported'] + $report['updated'];
    if ($report['failed'] === 0 && $totalProcessed > 0) {
        $syncState->markCompleted();
        $syncState->save();
        $checkpoint->clear('contacts-sync');
        output("Sync state saved, checkpoint cleared.");
    } else {
        output("WARNING: {$report['failed']} failures — sync state NOT updated (will retry next run).");
        output("Checkpoint preserved — next run will resume from last successful batch.");
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
output("Email fallback: " . ($report['email_fallback'] ?? 0));
output("Imported:       {$report['imported']}");
output("Updated:        {$report['updated']}");
output("Failed:         {$report['failed']}");
if (isset($report['gdpr_status'])) {
    output("GDPR cleanup:   {$report['gdpr_status']}");
    output("  Ecomail total:" . ($report['gdpr_ecomail_total'] ?? 0));
    output("  To delete:    " . ($report['gdpr_to_delete'] ?? 0));
    output("  Deleted:      " . ($report['gdpr_deleted'] ?? 0));
    output("  Delete failed:" . ($report['gdpr_delete_failed'] ?? 0));
}
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
    'email_fallback' => $report['email_fallback'] ?? 0,
    'imported' => $report['imported'],
    'updated' => $report['updated'],
    'failed' => $report['failed'],
    'gdpr_status' => $report['gdpr_status'] ?? null,
    'gdpr_deleted' => $report['gdpr_deleted'] ?? 0,
    'status' => $report['status'],
];

file_put_contents($statusFile, json_encode($statusData, JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX);

// Log full report (including skipped_contacts detail) then strip it for CLI output
$logger->info("Sync completed", $report);

// JSON output (CLI only — in web mode the connection is already closed)
if (!$isWeb) {
    // Exclude large skipped_contacts list from CLI output (it's in the log)
    $cliReport = $report;
    unset($cliReport['skipped_contacts']);
    echo json_encode($cliReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
