<?php

/**
 * Sync script: Reads rows from a public Google Sheet and creates
 * activities (events) on matching contacts in Anabix CRM.
 *
 * Google Sheet columns:
 *   A: EmailAddress - used to find the contact in Anabix
 *   B: Date         - date for the activity (YYYY-MM-DD)
 *   C: Reason       - content/body of the activity
 *
 * Usage: php sync-sheets.php
 */

require_once __DIR__ . '/src/env.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/SyncState.php';
require_once __DIR__ . '/src/AnabixClient.php';
require_once __DIR__ . '/src/GoogleSheetsClient.php';

// ── Load configuration ───────────────────────────────────────────────

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Error: .env file not found. Copy .env.example to .env and configure it.\n");
    exit(1);
}
loadEnv($envFile);

// ── Initialize components ────────────────────────────────────────────

$logger = new Logger(__DIR__ . '/storage/logs');
$syncState = new SyncState(__DIR__ . '/storage/state');

$anabix = new AnabixClient(
    env('ANABIX_API_USER'),
    env('ANABIX_API_TOKEN'),
    env('ANABIX_API_URL'),
    $logger
);

$sheets = new GoogleSheetsClient(
    env('GOOGLE_SHEET_ID'),
    env('GOOGLE_SHEET_GID', '0'),
    $logger
);

$activityIdUser = (int) env('ANABIX_ACTIVITY_ID_USER', '5');
$activityType = env('ANABIX_ACTIVITY_TYPE', 'note');
$activityTitle = env('ANABIX_ACTIVITY_TITLE', 'Odhlášení z newsletteru');

// Column mapping from Google Sheet
$colEmail = env('SHEET_COL_EMAIL', 'EmailAddress');
$colDate = env('SHEET_COL_DATE', 'Date');
$colReason = env('SHEET_COL_REASON', 'Reason');

// ── Console output helper ────────────────────────────────────────────

function output(string $message): void
{
    $time = date('H:i:s');
    echo "[{$time}] {$message}" . PHP_EOL;
}

// ── Run sync ─────────────────────────────────────────────────────────

output("=== Sync Google Sheets -> Anabix ===");
output("Spreadsheet ID: " . env('GOOGLE_SHEET_ID'));
output("Activity idUser: {$activityIdUser}");
output("");

$logger->info("Starting Google Sheets sync", [
    'sheet_id' => env('GOOGLE_SHEET_ID'),
    'idUser' => $activityIdUser,
]);

$report = [
    'status' => 'ok',
    'total_rows' => 0,
    'created' => 0,
    'skipped_synced' => 0,
    'skipped_not_found' => 0,
    'failed' => 0,
    'errors' => [],
];

try {
    // Fetch rows from Google Sheet
    output("Stahuji data z Google Sheets...");
    $rows = $sheets->fetchRows();
    $report['total_rows'] = count($rows);
    output("Naceteno radku: {$report['total_rows']}");
    output("");

    if (empty($rows)) {
        output("Zadne radky ke zpracovani.");
        $report['status'] = 'ok';
    }

    foreach ($rows as $index => $row) {
        $rowNum = $index + 2; // +2 because row 1 is header, index is 0-based
        $email = strtolower(trim($row[$colEmail] ?? ''));
        $date = trim($row[$colDate] ?? '');
        $reason = trim($row[$colReason] ?? '');

        // Skip empty rows
        if ($email === '') {
            output("  Radek {$rowNum}: PRESKOCEN (prazdny email)");
            $report['skipped_not_found']++;
            continue;
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            output("  Radek {$rowNum}: PRESKOCEN (neplatny email: {$email})");
            $logger->warning("Skipping invalid email from sheet", ['row' => $rowNum, 'email' => $email]);
            $report['skipped_not_found']++;
            continue;
        }

        // Check if this row was already synced (use email+date as key)
        $syncKey = "sheet:{$email}:{$date}";
        if ($syncState->isSynced($syncKey)) {
            output("  Radek {$rowNum}: PRESKOCEN (uz synchronizovano) - {$email}");
            $report['skipped_synced']++;
            continue;
        }

        // Find contact in Anabix by email
        output("  Radek {$rowNum}: Hledam kontakt {$email} v Anabixu...");
        $contact = $anabix->findContactByEmail($email);

        if ($contact === null) {
            output("  Radek {$rowNum}: PRESKOCEN (kontakt nenalezen v Anabixu) - {$email}");
            $logger->warning("Contact not found in Anabix", ['email' => $email, 'row' => $rowNum]);
            $report['skipped_not_found']++;
            continue;
        }

        $contactId = $contact['idContact'] ?? $contact['id'] ?? null;
        if ($contactId === null) {
            output("  Radek {$rowNum}: CHYBA (nelze zjistit ID kontaktu) - {$email}");
            $report['failed']++;
            $report['errors'][] = "Row {$rowNum}: Cannot extract contact ID for {$email}";
            continue;
        }

        // Build activity body from Reason column
        $body = $reason !== '' ? $reason : '(bez udani duvodu)';

        // Use the date from the sheet, default to now
        $timestamp = $date !== '' ? $date . ' 00:00:00' : date('Y-m-d H:i:s');

        // Create activity in Anabix
        $result = $anabix->createActivity(
            (int) $contactId,
            $activityTitle,
            $body,
            $activityType,
            $timestamp,
            $activityIdUser
        );

        if ($result !== null) {
            output("  Radek {$rowNum}: VYTVORENO - {$email} (datum: {$date}, idUser: {$activityIdUser})");
            $report['created']++;
            $syncState->markSynced($syncKey);
        } else {
            output("  Radek {$rowNum}: CHYBA pri vytvareni udalosti - {$email}");
            $report['failed']++;
            $report['errors'][] = "Row {$rowNum}: Failed to create activity for {$email}";
        }
    }

    $syncState->updateLastSync();
    $syncState->save();

} catch (Throwable $e) {
    $report['status'] = 'error';
    $report['errors'][] = $e->getMessage();
    output("CHYBA: " . $e->getMessage());
    $logger->error("Google Sheets sync failed", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}

// ── Summary ──────────────────────────────────────────────────────────

output("");
output("=== Souhrn ===");
output("Celkem radku:              {$report['total_rows']}");
output("Vytvorenych udalosti:      {$report['created']}");
output("Preskoceno (uz sync.):     {$report['skipped_synced']}");
output("Preskoceno (nenalezeno):   {$report['skipped_not_found']}");
output("Chyb:                      {$report['failed']}");
output("Status:                    {$report['status']}");

if (!empty($report['errors'])) {
    output("");
    output("Chyby:");
    foreach ($report['errors'] as $err) {
        output("  - {$err}");
    }
}

output("");

$logger->info("Google Sheets sync completed", $report);

// Output JSON report (useful for automated processing)
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
