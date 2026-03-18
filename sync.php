<?php

/**
 * CLI sync script: Fetches new contacts from Ecomail and syncs them to Anabix.
 *
 * Usage: php sync.php
 * Can be run manually or from cron.
 *
 * Output: JSON report with sync results.
 */

require_once __DIR__ . '/src/env.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/SyncState.php';
require_once __DIR__ . '/src/EcomailClient.php';
require_once __DIR__ . '/src/AnabixClient.php';
require_once __DIR__ . '/src/Transformer.php';

// Load configuration
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    echo("Error: .env file not found. Copy .env.example to .env and configure it.\n");
    exit(1);
}
loadEnv($envFile);

// Initialize components
$logger = new Logger(__DIR__ . '/storage/logs');
$syncState = new SyncState(__DIR__ . '/storage/state');

$ecomail = new EcomailClient(
    env('ECOMAIL_API_KEY'),
    env('ECOMAIL_API_URL', 'https://api2.ecomail.cz'),
    $logger
);

$anabix = new AnabixClient(
    env('ANABIX_API_USER'),
    env('ANABIX_API_TOKEN'),
    env('ANABIX_API_URL'),
    $logger
);

$listId = (int) env('ECOMAIL_LIST_ID', '1');
$groupIds = array_filter(array_map('intval', explode(',', env('ANABIX_GROUP_IDS', ''))));

// Run sync
$logger->info("Starting sync", ['list_id' => $listId, 'groups' => $groupIds]);

$report = [
    'status' => 'ok',
    'created' => 0,
    'updated' => 0,
    'skipped' => 0,
    'failed' => 0,
    'errors' => [],
];

try {
    // Fetch all subscribers from Ecomail
    $subscribers = $ecomail->getAllSubscribers($listId);
    $logger->info("Fetched subscribers from Ecomail", ['count' => count($subscribers)]);

    foreach ($subscribers as $subscriber) {
        $email = Transformer::getEmail($subscriber);

        // Skip invalid emails
        if (!Transformer::isValid($subscriber)) {
            $logger->warning("Skipping invalid subscriber", ['data' => $subscriber]);
            $report['skipped']++;
            continue;
        }

        // Skip already synced contacts
        if ($syncState->isSynced($email)) {
            $report['skipped']++;
            continue;
        }

        // Transform to Anabix format
        $contactData = Transformer::toAnabixContact($subscriber);

        // Check if contact already exists in Anabix
        $existingContact = $anabix->findContactByEmail($email);

        if ($existingContact !== null) {
            // Update existing contact
            $contactId = $existingContact['idContact'] ?? $existingContact['id'] ?? null;
            if ($contactId) {
                $result = $anabix->updateContact((int) $contactId, $contactData);
                if ($result !== null) {
                    $report['updated']++;
                    $syncState->markSynced($email);
                    $logger->info("Updated contact", ['email' => $email]);
                } else {
                    $report['failed']++;
                    $report['errors'][] = "Failed to update: {$email}";
                }
            }
        } else {
            // Create new contact
            $result = $anabix->createContact($contactData);
            if ($result !== null) {
                $report['created']++;
                $syncState->markSynced($email);
                $logger->info("Created contact", ['email' => $email]);

                // Add to groups
                $contactId = $result['idContact'] ?? $result['id'] ?? $result['data']['idContact'] ?? null;
                if ($contactId) {
                    foreach ($groupIds as $groupId) {
                        $anabix->addContactToGroup((int) $contactId, $groupId);
                    }
                }
            } else {
                $report['failed']++;
                $report['errors'][] = "Failed to create: {$email}";
            }
        }
    }

    $syncState->updateLastSync();
    $syncState->save();

} catch (Throwable $e) {
    $report['status'] = 'error';
    $report['errors'][] = $e->getMessage();
    $logger->error("Sync failed with exception", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}

$logger->info("Sync completed", $report);

// Output JSON report
header('Content-Type: application/json');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
