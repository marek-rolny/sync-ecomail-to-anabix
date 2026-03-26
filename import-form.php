<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * Universal Google Form → Anabix CRM import.
 *
 * Reads form name from argument, loads FORM_{NAME}_* config from .env,
 * fetches Google Sheet, and for each respondent:
 *   1. Find or create contact in Anabix (by email)
 *   2. Assign contact to configured lists
 *   3. Create activity (note) with all form responses
 *   4. Optionally create separate internal evaluation notes
 *
 * Google Sheet must be publicly shared (Anyone with the link can view).
 *
 * Usage:
 *   php import-form.php copywriters              (dry-run)
 *   php import-form.php copywriters --execute     (writes to Anabix)
 *   Browser: import-form.php?form=copywriters&execute=1
 */

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
require_once __DIR__ . '/src/Normalizer.php';

// ── Parse arguments ─────────────────────────────────────────────────

$formName = null;
$execute = false;
$fullRun = false;

if (php_sapi_name() === 'cli') {
    $formName = $argv[1] ?? null;
    $execute = in_array('--execute', $argv ?? [], true);
    $fullRun = in_array('--full', $argv ?? [], true);

    if ($formName === '--execute' || $formName === '--full') {
        $formName = $argv[2] ?? null;
    }
    if ($formName === null || $formName === '--execute' || $formName === '--full') {
        echo "Usage: php import-form.php <form-name> [--execute] [--full]\n";
        echo "  --execute  Write to Anabix (default: dry-run)\n";
        echo "  --full     Process all rows (ignore checkpoint)\n";
        echo "Example: php import-form.php copywriters --execute\n";
        exit(1);
    }
} else {
    $formName = $_GET['form'] ?? null;
    $execute = ($_GET['execute'] ?? '') === '1';
    $fullRun = ($_GET['full'] ?? '') === '1';

    if ($formName === null || $formName === '') {
        echo "Error: ?form=<name> parameter required.\n";
        echo "Example: import-form.php?form=copywriters&execute=1\n";
        exit(1);
    }
}

// ── Load configuration ──────────────────────────────────────────────

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    echo "Error: .env file not found.\n";
    exit(1);
}
loadEnv($envFile);

$prefix = 'FORM_' . strtoupper($formName) . '_';

/**
 * Read a FORM_{NAME}_* setting from env, with optional default.
 */
function formEnv(string $key, string $default = ''): string
{
    global $prefix;
    return env($prefix . $key, $default);
}

// Required settings
$anabixUser = env('ANABIX_USERNAME', '');
$anabixToken = env('ANABIX_TOKEN', '');
$anabixUrl = env('ANABIX_API_URL', '');
$sheetId = formEnv('SHEET_ID', '');
$sheetGid = formEnv('SHEET_GID', '0');

if ($anabixUser === '' || $anabixToken === '' || $anabixUrl === '' || $sheetId === '') {
    echo "Error: Need ANABIX_USERNAME, ANABIX_TOKEN, ANABIX_API_URL, {$prefix}SHEET_ID in .env\n";
    exit(1);
}

// Optional settings
$activityTitle = formEnv('ACTIVITY_TITLE', 'Dotazník: ' . ucfirst($formName));

$assignLists = [];
$listsStr = formEnv('LISTS', '');
if ($listsStr !== '') {
    $assignLists = array_map('intval', array_filter(explode(',', $listsStr)));
}

// Column mapping — match by header name
$colEmail = formEnv('COL_EMAIL');
$colName = formEnv('COL_NAME');
$colPhone = formEnv('COL_PHONE');
$colCity = formEnv('COL_CITY');
$colTimestamp = formEnv('COL_TIMESTAMP');

// Internal columns — separate activity notes per person
$internalCols = [];
$internalStr = formEnv('INTERNAL_COLS', '');
if ($internalStr !== '') {
    $internalCols = array_map('trim', explode(',', $internalStr));
}

// ── Initialize clients ──────────────────────────────────────────────

$logger = new Logger(__DIR__ . '/storage/logs');
$anabix = new AnabixClient($anabixUser, $anabixToken, $anabixUrl, $logger);
$sheets = new GoogleSheetsClient($sheetId, $sheetGid, $logger);

$activityIdUser = (int) env('ANABIX_ACTIVITY_ID_USER', '6');

// ── Checkpoint (incremental import) ─────────────────────────────────

$stateDir = __DIR__ . '/storage/state';
$stateFile = $stateDir . '/form-' . strtolower($formName) . '.json';

$lastTimestamp = null; // null = process all (first run or --full)

if (!$fullRun && file_exists($stateFile)) {
    $state = json_decode(file_get_contents($stateFile), true);
    $lastTimestamp = $state['last_timestamp'] ?? null;
}

/**
 * Parse a Czech timestamp ("17.2.2026 12:52:06") into a comparable DateTime.
 * Returns null if parsing fails.
 */
function parseTimestamp(string $ts): ?DateTimeImmutable
{
    $ts = trim($ts);
    if ($ts === '') {
        return null;
    }
    // Google Forms Czech format: d.m.Y H:i:s
    $dt = DateTimeImmutable::createFromFormat('d.m.Y H:i:s', $ts);
    if ($dt !== false) {
        return $dt;
    }
    // Fallback: try generic parsing
    try {
        return new DateTimeImmutable($ts);
    } catch (Exception $e) {
        return null;
    }
}

// ── Helpers ─────────────────────────────────────────────────────────

function output(string $msg): void
{
    $time = date('H:i:s');
    echo "[{$time}] {$msg}" . PHP_EOL;
    if (php_sapi_name() !== 'cli') {
        flush();
    }
}

/**
 * Clean city from free-text input.
 * "Kostelany nad Moravou – pracuji z domu" → "Kostelany nad Moravou"
 */
function cleanCity(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $city = preg_replace('/\s*[–—-]\s+.*$/u', '', $raw);
    $city = preg_replace('/\s*,\s.*$/u', '', $city);
    $city = preg_replace('/\s*\(.*\)\s*$/u', '', $city);
    return trim($city);
}

/**
 * Extract short discipline name from long Google Forms header.
 * "Copy disciplíny ... [E-mail marketing]" → "E-mail marketing"
 */
function extractDisciplineName(string $header): ?string
{
    if (preg_match('/\[([^\]]+)\]\s*$/', $header, $m)) {
        return trim($m[1]);
    }
    return null;
}

/**
 * Extract short question label from a long header.
 */
function extractQuestionLabel(string $header): string
{
    if (extractDisciplineName($header) !== null) {
        return extractDisciplineName($header);
    }
    $firstLine = strtok($header, "\n");
    $firstLine = rtrim(trim($firstLine), ": \t");
    if (mb_strlen($firstLine) > 80) {
        $firstLine = mb_substr($firstLine, 0, 80) . '…';
    }
    return $firstLine;
}

/**
 * Split "Jméno Příjmení" into [firstName, lastName].
 */
function splitName(string $fullName): array
{
    $parts = preg_split('/\s+/', trim($fullName), 2);
    return [
        $parts[0] ?? '',
        $parts[1] ?? '',
    ];
}

/**
 * Find column index by header name (case-insensitive, trimmed).
 * Returns null if not found.
 */
function findColumnIndex(array $headers, ?string $configValue): ?int
{
    if ($configValue === null || $configValue === '') {
        return null;
    }
    $needle = mb_strtolower(trim($configValue));
    foreach ($headers as $idx => $header) {
        if (mb_strtolower(trim($header)) === $needle) {
            return $idx;
        }
    }
    return null;
}

// ── Run import ──────────────────────────────────────────────────────

output("=== Import: Google Form ({$formName}) → Anabix ===");
output("Mode: " . ($execute ? "EXECUTE (writing to Anabix)" : "DRY-RUN (no changes)"));
if ($fullRun) {
    output("Run: FULL (processing all rows, ignoring checkpoint)");
} elseif ($lastTimestamp !== null) {
    output("Run: INCREMENTAL (rows newer than \"{$lastTimestamp}\")");
} else {
    output("Run: FIRST RUN (no checkpoint, processing all rows)");
}
output("Activity title: {$activityTitle}");
if (!empty($assignLists)) {
    output("Assign to lists: " . implode(', ', $assignLists));
}
if (!empty($internalCols)) {
    output("Internal columns: " . implode(', ', $internalCols));
}
output("");

$report = [
    'total_rows' => 0,
    'contacts_created' => 0,
    'contacts_found' => 0,
    'activities_created' => 0,
    'skipped_no_email' => 0,
    'skipped_old' => 0,
    'failed' => 0,
    'errors' => [],
];

$newestTimestamp = null; // track the newest timestamp seen during this run

try {
    output("Fetching Google Sheet...");
    $rows = $sheets->fetchRows();
    $report['total_rows'] = count($rows);
    output("Rows: {$report['total_rows']}");
    output("");

    if (empty($rows)) {
        output("No rows to process.");
        goto finish;
    }

    // Get headers from first row keys
    $headers = array_keys($rows[0]);

    // Show all headers for debugging column mapping
    output("Sheet headers:");
    foreach ($headers as $idx => $h) {
        $short = mb_strlen($h) > 60 ? mb_substr($h, 0, 60) . '…' : $h;
        output("  [{$idx}] \"{$short}\"");
    }
    output("");

    // Resolve column indices from config (by header name)
    $idxEmail = findColumnIndex($headers, $colEmail);
    $idxName = findColumnIndex($headers, $colName);
    $idxPhone = findColumnIndex($headers, $colPhone);
    $idxCity = findColumnIndex($headers, $colCity);
    $idxTimestamp = findColumnIndex($headers, $colTimestamp);

    // Email column is optional — without it, contacts are always created (no dedup)
    if ($idxEmail === null && $colEmail !== '') {
        throw new RuntimeException("Column '{$colEmail}' ({$prefix}COL_EMAIL) not found in sheet headers.");
    }

    // Track which column indices are "contact" columns (not included in note)
    $contactColIndices = array_filter([$idxEmail, $idxName, $idxPhone, $idxCity, $idxTimestamp], fn($v) => $v !== null);

    // Track which column indices are "internal" columns
    $internalColIndices = [];
    foreach ($headers as $idx => $header) {
        if (in_array(trim($header), $internalCols, true)) {
            $internalColIndices[$idx] = trim($header);
        }
    }

    // Form response columns = everything except contact + internal columns
    $formColIndices = [];
    for ($i = 0; $i < count($headers); $i++) {
        if (!in_array($i, $contactColIndices) && !isset($internalColIndices[$i])) {
            $formColIndices[] = $i;
        }
    }

    output("Mapped columns:");
    output("  Email: " . ($idxEmail !== null ? "#{$idxEmail} \"{$headers[$idxEmail]}\"" : "(not set)"));
    output("  Name: " . ($idxName !== null ? "#{$idxName} \"{$headers[$idxName]}\"" : "(not set)"));
    output("  Phone: " . ($idxPhone !== null ? "#{$idxPhone} \"{$headers[$idxPhone]}\"" : "(not set)"));
    output("  City: " . ($idxCity !== null ? "#{$idxCity} \"{$headers[$idxCity]}\"" : "(not set)"));
    output("  Timestamp: " . ($idxTimestamp !== null ? "#{$idxTimestamp} \"{$headers[$idxTimestamp]}\"" : "(not set)"));
    output("  Form response columns: " . count($formColIndices));
    output("  Internal columns: " . count($internalColIndices));
    output("");

    // Process each respondent
    foreach ($rows as $rowIndex => $row) {
        $rowNum = $rowIndex + 2; // +2: header + 0-based
        $values = array_values($row);

        $email = $idxEmail !== null ? strtolower(trim($values[$idxEmail] ?? '')) : '';
        $fullName = $idxName !== null ? trim($values[$idxName] ?? '') : '';
        $phone = $idxPhone !== null ? trim($values[$idxPhone] ?? '') : '';
        $cityRaw = $idxCity !== null ? trim($values[$idxCity] ?? '') : '';
        $timestamp = $idxTimestamp !== null ? trim($values[$idxTimestamp] ?? '') : '';

        // ── Incremental: skip rows older than checkpoint ────────────
        $rowDt = null;
        if ($lastTimestamp !== null && $timestamp !== '' && $idxTimestamp !== null) {
            $rowDt = parseTimestamp($timestamp);
            $lastDt = parseTimestamp($lastTimestamp);
            if ($rowDt !== null && $lastDt !== null && $rowDt <= $lastDt) {
                $report['skipped_old']++;
                continue;
            }
        }

        // Track newest timestamp for checkpoint save
        if ($timestamp !== '') {
            $rowDt = $rowDt ?? parseTimestamp($timestamp);
            $newestDt = $newestTimestamp !== null ? parseTimestamp($newestTimestamp) : null;
            if ($rowDt !== null && ($newestDt === null || $rowDt > $newestDt)) {
                $newestTimestamp = $timestamp;
            }
        }

        // Validate email if present
        $hasEmail = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
        if (!$hasEmail) {
            $email = ''; // clear invalid email
        }

        // Need at least a name or email to create a contact
        if ($email === '' && $fullName === '') {
            output("  Row {$rowNum}: SKIP (no email and no name)");
            $report['skipped_no_email']++;
            continue;
        }

        [$firstName, $lastName] = splitName($fullName);
        $phoneReadable = $phone !== '' ? DataNormalizer::phoneToReadable($phone) : null;
        $phoneE164 = $phone !== '' ? DataNormalizer::phoneToE164($phone) : null;
        $city = cleanCity($cityRaw);

        $label = $hasEmail ? "{$fullName} <{$email}>" : "{$fullName} (no email)";
        output("  Row {$rowNum}: {$label}");
        if ($phone !== '') {
            output("    Phone: {$phone} → " . ($phoneReadable ?? 'invalid'));
        }
        if ($cityRaw !== '') {
            output("    City: \"{$cityRaw}\" → \"{$city}\"");
        }

        // ── Find or create contact ──────────────────────────────────
        $contactId = null;

        if ($execute) {
            // Try to find existing contact by email (only if email is available)
            $existing = null;
            if ($hasEmail) {
                $existing = $anabix->findContactByEmail($email);
                usleep(300000);
            }

            if ($existing !== null) {
                $contactId = $existing['idContact'] ?? $existing['id'] ?? null;
                output("    Contact FOUND: #{$contactId}");
                $report['contacts_found']++;
            } else {
                // Parse GDPR acceptance date from timestamp
                $gdprDate = '';
                if ($timestamp !== '') {
                    $gdprDate = DataNormalizer::normalizeDate($timestamp);
                }

                $contactData = [
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'idOwner' => $activityIdUser,
                    'source' => $activityTitle,
                    'gdprReason' => 5,
                ];
                if ($hasEmail) {
                    $contactData['email'] = $email;
                }
                if ($gdprDate !== '') {
                    $contactData['gdprAcceptanceDate'] = $gdprDate;
                }
                if ($phoneReadable !== null) {
                    $contactData['phoneNumber'] = $phoneReadable;
                }
                if ($city !== '') {
                    $contactData['shippingCity'] = $city;
                }

                $created = $anabix->createContact($contactData);
                usleep(300000);

                if ($created !== null) {
                    $contactId = $created['idContact'] ?? $created['id'] ?? null;
                    output("    Contact CREATED: #{$contactId}");
                    $report['contacts_created']++;
                } else {
                    output("    Contact CREATE FAILED");
                    $report['failed']++;
                    $report['errors'][] = "Row {$rowNum}: Failed to create contact {$email}";
                    continue;
                }
            }
        } else {
            output("    [DRY-RUN] Would find/create contact");
        }

        // ── Assign contact to lists ─────────────────────────────────
        if ($execute && $contactId !== null && !empty($assignLists)) {
            if ($anabix->addContactToLists((int) $contactId, $assignLists)) {
                output("    Added to lists: " . implode(', ', $assignLists));
            } else {
                output("    WARNING: Failed to add to lists");
            }
            usleep(200000);
        } elseif (!$execute && !empty($assignLists)) {
            output("    [DRY-RUN] Would assign to lists: " . implode(', ', $assignLists));
        }

        // ── Build activity note from form responses ─────────────────
        $noteLines = [];
        $noteLines[] = $activityTitle;
        if ($timestamp !== '') {
            $noteLines[] = "Vyplněno: {$timestamp}";
        }
        $noteLines[] = "";

        $disciplineSection = true;
        $disciplineHeaderDone = false;

        foreach ($formColIndices as $col) {
            $header = $headers[$col] ?? '';
            $value = trim($values[$col] ?? '');

            if ($value === '') {
                continue;
            }

            $disciplineName = extractDisciplineName($header);

            if ($disciplineName !== null) {
                if (!$disciplineHeaderDone) {
                    $noteLines[] = "── Copy disciplíny (sebehodnocení 1-5) ──";
                    $disciplineHeaderDone = true;
                }
                $noteLines[] = "{$disciplineName}: {$value}";
            } else {
                if ($disciplineSection && $disciplineHeaderDone) {
                    $noteLines[] = "";
                    $disciplineSection = false;
                }

                $label = extractQuestionLabel($header);
                $noteLines[] = "── {$label} ──";
                $noteLines[] = $value;
                $noteLines[] = "";
            }
        }

        $noteBody = implode("\n", $noteLines);

        // ── Build internal evaluation notes (one per person) ────────
        $sheetUrl = "https://docs.google.com/spreadsheets/d/{$sheetId}/";
        $internalNotes = [];

        foreach ($internalColIndices as $idx => $colName_) {
            $value = trim($values[$idx] ?? '');
            if ($value !== '') {
                $internalNotes[$colName_] = $value;
            }
        }

        if ($execute && $contactId !== null) {
            // Activity: Form responses
            $result = $anabix->createActivity(
                (int) $contactId,
                $activityTitle,
                $noteBody,
                'note',
                $timestamp !== '' ? $timestamp : null,
                $activityIdUser
            );
            usleep(300000);

            if ($result !== null) {
                output("    Activity CREATED (form responses)");
                $report['activities_created']++;
            } else {
                output("    Activity CREATE FAILED (form responses)");
                $report['failed']++;
                $report['errors'][] = "Row {$rowNum}: Failed to create form activity for {$email}";
            }

            // Internal evaluation notes (one per person)
            foreach ($internalNotes as $person => $noteText) {
                $internalBody = $noteText . "\n--\nzdroj: {$sheetUrl}\n--";

                $result = $anabix->createActivity(
                    (int) $contactId,
                    "Poznámka {$person}",
                    $internalBody,
                    'note',
                    null,
                    $activityIdUser
                );
                usleep(300000);

                if ($result !== null) {
                    output("    Activity CREATED (Poznámka {$person})");
                    $report['activities_created']++;
                } else {
                    output("    Activity CREATE FAILED (Poznámka {$person})");
                    $report['failed']++;
                    $report['errors'][] = "Row {$rowNum}: Failed to create note {$person} for {$email}";
                }
            }
        } else {
            output("    [DRY-RUN] Note preview (" . mb_strlen($noteBody) . " chars):");
            $preview = array_slice(explode("\n", $noteBody), 0, 15);
            foreach ($preview as $line) {
                output("      | {$line}");
            }
            if (count(explode("\n", $noteBody)) > 15) {
                output("      | ...");
            }

            if (!empty($internalNotes)) {
                output("    [DRY-RUN] Internal notes:");
                foreach ($internalNotes as $person => $noteText) {
                    $short = mb_strlen($noteText) > 60 ? mb_substr($noteText, 0, 60) . '…' : $noteText;
                    output("      | Poznámka {$person}: {$short}");
                }
            }
        }

        output("");
    }

} catch (Throwable $e) {
    $report['errors'][] = $e->getMessage();
    output("ERROR: " . $e->getMessage());
    $logger->error("Form import failed", [
        'form' => $formName,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}

finish:

// ── Save checkpoint ─────────────────────────────────────────────────
if ($execute && $newestTimestamp !== null) {
    if (!is_dir($stateDir)) {
        mkdir($stateDir, 0755, true);
    }
    file_put_contents($stateFile, json_encode([
        'last_timestamp' => $newestTimestamp,
        'updated_at' => date('Y-m-d H:i:s'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    output("");
    output("Checkpoint saved: \"{$newestTimestamp}\"");
}

output("");
output("=== Summary ===");
output("Total rows:         {$report['total_rows']}");
output("Contacts found:     {$report['contacts_found']}");
output("Contacts created:   {$report['contacts_created']}");
output("Activities created: {$report['activities_created']}");
output("Skipped (old):      {$report['skipped_old']}");
output("Skipped (no email): {$report['skipped_no_email']}");
output("Failed:             {$report['failed']}");

if (!empty($report['errors'])) {
    output("");
    output("Errors:");
    foreach ($report['errors'] as $err) {
        output("  - {$err}");
    }
}

$logger->info("Form import completed", array_merge(['form' => $formName], $report));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
