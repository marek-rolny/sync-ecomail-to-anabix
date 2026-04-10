<?php

/**
 * Sync script: Reads rows from a public Google Sheet and creates
 * activities (events) on matching contacts in Anabix CRM.
 *
 * Google Sheet columns (configurable via .env):
 *   A: EmailAddress - used to find the contact in Anabix
 *   B: Date         - date for the activity (YYYY-MM-DD)
 *   C: Reason       - content/body of the activity
 *
 * Usage: php sync-sheets.php
 */

// ── Deploy smoke-test endpoint (must stay at top, no dependencies) ───
// Usage: GET ?ping=1 → JSON {script, mtime, status}. Used by CI
// to verify that the file was deployed and PHP is able to execute it.
if (php_sapi_name() !== 'cli' && ($_GET['ping'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'script' => basename(__FILE__),
        'mtime'  => date('c', (int) @filemtime(__FILE__)),
        'status' => 'ok',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

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
require_once __DIR__ . '/src/GoogleSheetsClient.php';

// ── Load configuration ───────────────────────────────────────────────

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    echo "Error: .env file not found. Copy .env.example to .env and configure it.\n";
    exit(1);
}
loadEnv($envFile);

// ── Validate required config ─────────────────────────────────────────
// Support both old (ANABIX_API_USER/TOKEN) and new (ANABIX_USERNAME/TOKEN) env var names

$anabixUser = env('ANABIX_USERNAME', '') ?: env('ANABIX_API_USER', '');
$anabixToken = env('ANABIX_TOKEN', '') ?: env('ANABIX_API_TOKEN', '');
$anabixUrl = env('ANABIX_API_URL', '');

if ($anabixUser === '' || $anabixToken === '' || $anabixUrl === '' || env('GOOGLE_SHEET_ID') === '') {
    echo "Error: Required config not set. Need ANABIX_USERNAME, ANABIX_TOKEN, ANABIX_API_URL, GOOGLE_SHEET_ID\n";
    exit(1);
}

// ── Initialize components ────────────────────────────────────────────

$logger = new Logger(__DIR__ . '/storage/logs');

$anabix = new AnabixClient($anabixUser, $anabixToken, $anabixUrl, $logger);

$sheets = new GoogleSheetsClient(
    env('GOOGLE_SHEET_ID'),
    env('GOOGLE_SHEET_GID', '0'),
    $logger
);

$activityIdUser = (int) env('ANABIX_ACTIVITY_ID_USER', '6');
$activityType = env('ANABIX_ACTIVITY_TYPE', 'note');
$activityTitle = env('ANABIX_ACTIVITY_TITLE', 'Odhlášení z newsletteru');

// Column mapping from Google Sheet
$colEmail = env('SHEET_COL_EMAIL', 'EmailAddress');
$colDate = env('SHEET_COL_DATE', 'Date');
$colReason = env('SHEET_COL_REASON', 'Reason');

// ── Row deduplication state (own state file, not shared with contacts sync) ──

$stateFile = __DIR__ . '/storage/state/sheets-sync-state.json';
$processedKeys = [];
if (file_exists($stateFile)) {
    $processedKeys = json_decode(file_get_contents($stateFile), true) ?: [];
}

// ── Console output helper ────────────────────────────────────────────

function output(string $message): void
{
    $time = date('H:i:s');
    echo "[{$time}] {$message}" . PHP_EOL;
    if (php_sapi_name() !== 'cli') {
        flush();
    }
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
    output("Stahuji data z Google Sheets...");
    $rows = $sheets->fetchRows();
    $report['total_rows'] = count($rows);
    output("Naceteno radku: {$report['total_rows']}");
    output("");

    if (empty($rows)) {
        output("Zadne radky ke zpracovani.");
    }

    foreach ($rows as $index => $row) {
        $rowNum = $index + 2; // +2: row 1 = header, index 0-based
        $email = strtolower(trim($row[$colEmail] ?? ''));
        $date = trim($row[$colDate] ?? '');
        $reason = trim($row[$colReason] ?? '');

        if ($email === '') {
            $report['skipped_not_found']++;
            continue;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            output("  Radek {$rowNum}: PRESKOCEN (neplatny email: {$email})");
            $report['skipped_not_found']++;
            continue;
        }

        // Dedup: email+date+reason
        $stateKey = md5("sheet:{$email}:{$date}:{$reason}");
        if (isset($processedKeys[$stateKey])) {
            $report['skipped_synced']++;
            continue;
        }

        // Find contact in Anabix
        output("  Radek {$rowNum}: Hledam kontakt {$email}...");
        $contact = $anabix->findContactByEmail($email);

        if ($contact === null) {
            output("  Radek {$rowNum}: PRESKOCEN (nenalezeno) - {$email}");
            $report['skipped_not_found']++;
            usleep(200000);
            continue;
        }

        $contactId = $contact['idContact'] ?? $contact['id'] ?? null;
        if ($contactId === null) {
            $report['failed']++;
            $report['errors'][] = "Row {$rowNum}: No contact ID for {$email}";
            continue;
        }

        // Build activity
        $reasonText = $reason !== '' ? $reason : '(bez udani duvodu)';
        $body = "Datum: {$date}\nDůvod: {$reasonText}";
        $timestamp = $date !== '' ? $date . ' 00:00:00' : date('Y-m-d H:i:s');

        output("  Radek {$rowNum}: Vytvarim udalost pro #{$contactId} ({$email})");

        $result = $anabix->createActivity(
            (int) $contactId,
            $activityTitle,
            $body,
            $activityType,
            $timestamp,
            $activityIdUser
        );

        if ($result !== null) {
            output("  Radek {$rowNum}: VYTVORENO - {$email}");
            $report['created']++;
            $processedKeys[$stateKey] = true;
        } else {
            output("  Radek {$rowNum}: CHYBA - {$email}");
            $report['failed']++;
            $report['errors'][] = "Row {$rowNum}: Failed for {$email}";
        }

        usleep(200000); // rate limiting
    }

    // Save dedup state
    $stateDir = dirname($stateFile);
    if (!is_dir($stateDir)) {
        mkdir($stateDir, 0755, true);
    }
    file_put_contents($stateFile, json_encode($processedKeys, JSON_PRETTY_PRINT), LOCK_EX);

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

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
