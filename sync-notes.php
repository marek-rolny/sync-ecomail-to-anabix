<?php

/**
 * Sync notes from Google Sheets (Campaign Monitor export) to Anabix.
 *
 * Reads a CSV (exported from Google Sheets) where:
 *   Column A = email address (key for finding contact in Anabix)
 *   Column B = date of the event
 *   Column C = reason / content (e.g. "MarkedAsSpam", "Unsubscribed", etc.)
 *
 * For each row, finds the contact in Anabix by email and creates
 * a note-type activity with the given date and content.
 *
 * Usage:
 *   php sync-notes.php                          # Uses GOOGLE_SHEET_CSV_URL from .env
 *   php sync-notes.php /path/to/export.csv      # Uses local CSV file
 *   php sync-notes.php --dry-run                 # Preview without creating notes
 *   php sync-notes.php /path/to/export.csv --dry-run
 */

require_once __DIR__ . '/src/env.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/AnabixClient.php';

// Load configuration
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Error: .env file not found. Copy .env.example to .env and configure it.\n");
    exit(1);
}
loadEnv($envFile);

// Parse CLI arguments
$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$args = array_filter($args, fn($a) => $a !== '--dry-run');
$csvSource = reset($args) ?: '';

// Determine CSV source
if ($csvSource === '') {
    $csvSource = env('GOOGLE_SHEET_CSV_URL', '');
    if ($csvSource === '') {
        fwrite(STDERR, "Error: No CSV source provided.\n");
        fwrite(STDERR, "Usage: php sync-notes.php [path/to/file.csv|URL] [--dry-run]\n");
        fwrite(STDERR, "Or set GOOGLE_SHEET_CSV_URL in .env\n");
        exit(1);
    }
}

// Initialize components
$logger = new Logger(__DIR__ . '/storage/logs');
$anabix = new AnabixClient(
    env('ANABIX_API_USER'),
    env('ANABIX_API_TOKEN'),
    env('ANABIX_API_URL'),
    $logger
);

$notePrefix = env('CAMPAIGN_MONITOR_NOTE_PREFIX', 'Campaign Monitor Reason');

// Load CSV data
$logger->info("Loading CSV data", ['source' => $csvSource, 'dry_run' => $dryRun]);

$rows = loadCsvData($csvSource);
if ($rows === null) {
    fwrite(STDERR, "Error: Failed to load CSV from: {$csvSource}\n");
    exit(1);
}

$logger->info("Loaded CSV rows", ['count' => count($rows)]);

if ($dryRun) {
    fwrite(STDOUT, "DRY RUN mode - no changes will be made in Anabix.\n\n");
}

// Process rows
$report = [
    'status' => 'ok',
    'total_rows' => count($rows),
    'notes_created' => 0,
    'contacts_not_found' => 0,
    'skipped' => 0,
    'failed' => 0,
    'errors' => [],
];

// State file to track already-processed rows
$stateFile = __DIR__ . '/storage/state/sync-notes-state.json';
$processedKeys = loadProcessedState($stateFile);

foreach ($rows as $index => $row) {
    $email = trim($row['email'] ?? '');
    $date = trim($row['date'] ?? '');
    $reason = trim($row['reason'] ?? '');

    // Skip empty rows
    if ($email === '' || $reason === '') {
        $report['skipped']++;
        continue;
    }

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $logger->warning("Skipping invalid email", ['row' => $index + 1, 'email' => $email]);
        $report['skipped']++;
        continue;
    }

    // Build a unique key for deduplication
    $stateKey = md5(strtolower($email) . '|' . $date . '|' . $reason);

    if (isset($processedKeys[$stateKey])) {
        $report['skipped']++;
        continue;
    }

    // Normalize date
    $timestamp = parseDate($date);
    if ($timestamp === null) {
        $logger->warning("Skipping row with invalid date", ['row' => $index + 1, 'date' => $date]);
        $report['skipped']++;
        continue;
    }

    // Build note content
    $noteTitle = "{$notePrefix}: {$reason}";
    $noteBody = "{$notePrefix}: {$reason}\nDate: {$date}\nEmail: {$email}";

    if ($dryRun) {
        fwrite(STDOUT, sprintf(
            "[Row %d] %s | %s | %s\n",
            $index + 1,
            $email,
            $timestamp,
            $noteTitle
        ));
        $report['notes_created']++;
        continue;
    }

    // Find contact in Anabix
    $contact = $anabix->findContactByEmail($email);

    if ($contact === null) {
        $logger->warning("Contact not found in Anabix", ['email' => $email]);
        $report['contacts_not_found']++;
        $report['errors'][] = "Contact not found: {$email}";
        continue;
    }

    $contactId = (int) ($contact['idContact'] ?? $contact['id'] ?? 0);
    if ($contactId === 0) {
        $logger->error("Could not determine contact ID", ['email' => $email, 'contact' => $contact]);
        $report['failed']++;
        $report['errors'][] = "No contact ID for: {$email}";
        continue;
    }

    // Create note activity
    $result = $anabix->createActivity($contactId, $noteTitle, $noteBody, 'note', $timestamp);

    if ($result !== null) {
        $report['notes_created']++;
        $processedKeys[$stateKey] = true;
        $logger->info("Created note", ['email' => $email, 'contact_id' => $contactId, 'title' => $noteTitle]);
    } else {
        $report['failed']++;
        $report['errors'][] = "Failed to create note for: {$email}";
    }

    // Small delay to avoid API rate limiting
    usleep(200000); // 200ms
}

// Save state
if (!$dryRun) {
    saveProcessedState($stateFile, $processedKeys);
}

$logger->info("Sync notes completed", $report);

// Output JSON report
header('Content-Type: application/json');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

// --- Helper functions ---

/**
 * Load CSV data from a file path or URL.
 * Returns array of ['email' => ..., 'date' => ..., 'reason' => ...] or null on failure.
 */
function loadCsvData(string $source): ?array
{
    $isUrl = preg_match('#^https?://#i', $source);

    if ($isUrl) {
        $csvContent = fetchUrl($source);
        if ($csvContent === null) {
            return null;
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csvContent);
        rewind($stream);
    } else {
        if (!file_exists($source)) {
            return null;
        }
        $stream = fopen($source, 'r');
    }

    if ($stream === false) {
        return null;
    }

    $rows = [];
    $lineNumber = 0;

    while (($fields = fgetcsv($stream)) !== false) {
        $lineNumber++;

        // Skip header row if it looks like a header
        if ($lineNumber === 1 && isHeaderRow($fields)) {
            continue;
        }

        // We need at least 3 columns (A=email, B=date, C=reason)
        if (count($fields) < 3) {
            continue;
        }

        $rows[] = [
            'email' => $fields[0],
            'date' => $fields[1],
            'reason' => $fields[2],
        ];
    }

    fclose($stream);
    return $rows;
}

/**
 * Check if a CSV row looks like a header.
 */
function isHeaderRow(array $fields): bool
{
    $firstField = strtolower(trim($fields[0] ?? ''));
    return in_array($firstField, ['email', 'e-mail', 'emailaddress', 'email_address', 'mail'], true);
}

/**
 * Fetch content from a URL using cURL.
 */
function fetchUrl(string $url): ?string
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode !== 200) {
        fwrite(STDERR, "Error fetching URL: HTTP {$httpCode}, {$error}\n");
        return null;
    }

    return $response;
}

/**
 * Parse a date string into Y-m-d H:i:s format.
 * Supports various common formats.
 */
function parseDate(string $date): ?string
{
    if ($date === '') {
        return null;
    }

    // Try common formats
    $formats = [
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d',
        'd.m.Y H:i:s',
        'd.m.Y H:i',
        'd.m.Y',
        'd/m/Y H:i:s',
        'd/m/Y',
        'm/d/Y H:i:s',
        'm/d/Y',
        'Y-m-d\TH:i:s',
        'Y-m-d\TH:i:sP',
    ];

    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $date);
        if ($dt !== false) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    // Fallback: let PHP try to parse it
    try {
        $dt = new DateTimeImmutable($date);
        return $dt->format('Y-m-d H:i:s');
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * Load processed state from JSON file.
 */
function loadProcessedState(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

/**
 * Save processed state to JSON file.
 */
function saveProcessedState(string $file, array $keys): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($file, json_encode($keys, JSON_PRETTY_PRINT));
}
