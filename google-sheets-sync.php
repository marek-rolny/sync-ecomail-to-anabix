<?php

/**
 * Sync script: Reads rows from Google Sheets and creates notes in Anabix.
 *
 * Google Sheet columns:
 *   A: Email (matched to Anabix contact)
 *   B: Date (used as note timestamp, format: YYYY-MM-DD)
 *   C: Reason (used in note body as "Campaign Monitor Reason: {value}")
 *
 * Usage: php google-sheets-sync.php
 */

require_once __DIR__ . '/src/env.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/SyncState.php';
require_once __DIR__ . '/src/AnabixClient.php';
require_once __DIR__ . '/src/GoogleSheetsClient.php';

// Load configuration
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    echo("Error: .env file not found. Copy .env.example to .env and configure it.\n");
    exit(1);
}
loadEnv($envFile);

// Validate required config
$requiredVars = ['GOOGLE_API_KEY', 'GOOGLE_SPREADSHEET_ID', 'GOOGLE_SHEET_NAME', 'ANABIX_API_USER', 'ANABIX_API_TOKEN', 'ANABIX_API_URL'];
foreach ($requiredVars as $var) {
    if (env($var) === '') {
        echo("Error: {$var} is not set in .env\n");
        exit(1);
    }
}

// Initialize components
$logger = new Logger(__DIR__ . '/storage/logs');
$stateDir = __DIR__ . '/storage/state';
$stateFile = $stateDir . '/google-sheets-state.json';

$googleSheets = new GoogleSheetsClient(
    env('GOOGLE_API_KEY'),
    $logger
);

$anabix = new AnabixClient(
    env('ANABIX_API_USER'),
    env('ANABIX_API_TOKEN'),
    env('ANABIX_API_URL'),
    $logger
);

// Load processed rows state (to avoid duplicate notes)
$processedState = [];
if (file_exists($stateFile)) {
    $processedState = json_decode(file_get_contents($stateFile), true) ?: [];
}
$processedKeys = $processedState['processed'] ?? [];

// Run sync
$logger->info("Starting Google Sheets → Anabix notes sync");

$report = [
    'status' => 'ok',
    'notes_created' => 0,
    'skipped' => 0,
    'contact_not_found' => 0,
    'failed' => 0,
    'errors' => [],
];

try {
    $rows = $googleSheets->getRows(
        env('GOOGLE_SPREADSHEET_ID'),
        env('GOOGLE_SHEET_NAME')
    );

    if (empty($rows)) {
        $logger->warning("No data returned from Google Sheets");
        $report['status'] = 'empty';
        goto output;
    }

    // Skip header row
    $dataRows = array_slice($rows, 1);
    $logger->info("Fetched rows from Google Sheets", ['count' => count($dataRows)]);

    foreach ($dataRows as $index => $row) {
        $rowNum = $index + 2; // 1-indexed, +1 for header

        $email = trim($row[0] ?? '');
        $date = trim($row[1] ?? '');
        $reason = trim($row[2] ?? '');

        // Validate row
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $logger->warning("Skipping row {$rowNum}: invalid email", ['email' => $email]);
            $report['skipped']++;
            continue;
        }

        // Create unique key for this row to prevent duplicates
        $rowKey = md5(strtolower($email) . '|' . $date . '|' . $reason);

        if (in_array($rowKey, $processedKeys, true)) {
            $report['skipped']++;
            continue;
        }

        // Find contact in Anabix
        $contact = $anabix->findContactByEmail($email);

        if ($contact === null) {
            $logger->warning("Contact not found in Anabix", ['email' => $email, 'row' => $rowNum]);
            $report['contact_not_found']++;
            continue;
        }

        $contactId = $contact['idContact'] ?? $contact['id'] ?? null;

        if ($contactId === null) {
            $logger->error("Could not determine contact ID", ['email' => $email, 'contact' => $contact]);
            $report['failed']++;
            $report['errors'][] = "No contact ID for: {$email}";
            continue;
        }

        // Create note
        $title = 'Campaign Monitor Reason';
        $body = "Campaign Monitor Reason: {$reason}";
        $timestamp = $date !== '' ? $date . ' 00:00:00' : null;

        $result = $anabix->createActivity((int) $contactId, $title, $body, 'note', $timestamp);

        if ($result !== null) {
            $report['notes_created']++;
            $processedKeys[] = $rowKey;
            $logger->info("Created note", ['email' => $email, 'reason' => $reason, 'date' => $date]);
        } else {
            $report['failed']++;
            $report['errors'][] = "Failed to create note for: {$email}";
        }
    }

    // Save processed state
    file_put_contents($stateFile, json_encode([
        'processed' => $processedKeys,
        'last_sync' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

} catch (Throwable $e) {
    $report['status'] = 'error';
    $report['errors'][] = $e->getMessage();
    $logger->error("Google Sheets sync failed", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}

output:
$logger->info("Google Sheets sync completed", $report);

header('Content-Type: application/json');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
