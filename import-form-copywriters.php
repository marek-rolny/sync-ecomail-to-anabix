<?php

/**
 * One-time import: Google Form (Copywriter questionnaire) → Anabix CRM.
 *
 * For each respondent:
 *  1. Find or create contact in Anabix (by email)
 *  2. Create activity (note) with all form responses
 *
 * Google Sheet must be publicly shared (Anyone with the link can view).
 *
 * Usage:
 *   php import-form-copywriters.php              (dry-run — shows what would happen)
 *   php import-form-copywriters.php --execute     (actually creates contacts & activities)
 *   Open in browser: import-form-copywriters.php?execute=1
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

// ── Load configuration ───────────────────────────────────────────────

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    echo "Error: .env file not found.\n";
    exit(1);
}
loadEnv($envFile);

$anabixUser = env('ANABIX_USERNAME', '');
$anabixToken = env('ANABIX_TOKEN', '');
$anabixUrl = env('ANABIX_API_URL', '');
$sheetId = env('FORM_COPYWRITERS_SHEET_ID', '');
$sheetGid = env('FORM_COPYWRITERS_SHEET_GID', '0');

if ($anabixUser === '' || $anabixToken === '' || $anabixUrl === '' || $sheetId === '') {
    echo "Error: Need ANABIX_USERNAME, ANABIX_TOKEN, ANABIX_API_URL, FORM_COPYWRITERS_SHEET_ID in .env\n";
    exit(1);
}

// Dry-run by default — pass --execute or ?execute=1 to actually write
$execute = false;
if (php_sapi_name() === 'cli') {
    $execute = in_array('--execute', $argv ?? [], true);
} else {
    $execute = ($_GET['execute'] ?? '') === '1';
}

$logger = new Logger(__DIR__ . '/storage/logs');
$anabix = new AnabixClient($anabixUser, $anabixToken, $anabixUrl, $logger);
$sheets = new GoogleSheetsClient($sheetId, $sheetGid, $logger);

$activityIdUser = (int) env('ANABIX_ACTIVITY_ID_USER', '6');

// ── Helpers ──────────────────────────────────────────────────────────

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
 * "Velký Osek, okr. Kolín" → "Velký Osek"
 * "Jičín" → "Jičín"
 */
function cleanCity(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    // Remove everything after dash variants (–, -, —) with spaces around them
    $city = preg_replace('/\s*[–—-]\s+.*$/u', '', $raw);

    // Remove everything after comma (okr., okres, etc.)
    $city = preg_replace('/\s*,\s.*$/u', '', $city);

    // Remove parenthetical suffixes
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
 * Takes text before the first newline or instruction block.
 */
function extractQuestionLabel(string $header): string
{
    // If it has brackets, it's a discipline rating — handle separately
    if (extractDisciplineName($header) !== null) {
        return extractDisciplineName($header);
    }

    // Take first line (before any newline)
    $firstLine = strtok($header, "\n");
    $firstLine = trim($firstLine);

    // Remove trailing colons and whitespace
    $firstLine = rtrim($firstLine, ": \t");

    // Truncate if too long
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

// ── Run import ───────────────────────────────────────────────────────

output("=== Import: Google Form (Copywriters) → Anabix ===");
output("Mode: " . ($execute ? "EXECUTE (writing to Anabix)" : "DRY-RUN (no changes)"));
output("");

$report = [
    'total_rows' => 0,
    'contacts_created' => 0,
    'contacts_found' => 0,
    'activities_created' => 0,
    'skipped_no_email' => 0,
    'failed' => 0,
    'errors' => [],
];

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

    // Identify column groups by index
    // A=0: Timestamp, B=1: Name, C=2: Email, D=3: Phone, E=4: City
    // F onwards: form responses (discipline ratings + text questions)
    // Last 7 columns: Dáška, Lucka, Vašek, Marek, Anička, Napíšeme, STAV (internal — skip for now)

    $internalCols = ['Dáška', 'Lucka', 'Vašek', 'Marek', 'Anička', 'Napíšeme', 'STAV'];

    // Find where form responses start (column index 5 = F) and where internal cols begin
    $formStartIdx = 5; // Column F
    $formEndIdx = count($headers) - 1;

    // Find the first internal column to know where form answers end
    foreach ($headers as $idx => $header) {
        if (in_array(trim($header), $internalCols, true)) {
            $formEndIdx = $idx - 1;
            break;
        }
    }

    output("Form response columns: {$formStartIdx} to {$formEndIdx} (" . ($formEndIdx - $formStartIdx + 1) . " columns)");
    output("");

    // Process each respondent
    foreach ($rows as $rowIndex => $row) {
        $rowNum = $rowIndex + 2; // +2: header + 0-based
        $values = array_values($row);

        $fullName = trim($values[1] ?? '');
        $email = strtolower(trim($values[2] ?? ''));
        $phone = trim($values[3] ?? '');
        $cityRaw = trim($values[4] ?? '');
        $timestamp = trim($values[0] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            output("  Row {$rowNum}: SKIP (no valid email)");
            $report['skipped_no_email']++;
            continue;
        }

        [$firstName, $lastName] = splitName($fullName);
        $phoneE164 = DataNormalizer::phoneToE164($phone);
        $city = cleanCity($cityRaw);

        output("  Row {$rowNum}: {$fullName} <{$email}>");
        output("    Phone: {$phone} → " . ($phoneE164 ?? 'invalid'));
        output("    City: \"{$cityRaw}\" → \"{$city}\"");

        // ── Find or create contact ──────────────────────────────────
        $contactId = null;

        if ($execute) {
            $existing = $anabix->findContactByEmail($email);
            usleep(300000);

            if ($existing !== null) {
                $contactId = $existing['idContact'] ?? $existing['id'] ?? null;
                output("    Contact FOUND: #{$contactId}");
                $report['contacts_found']++;
            } else {
                // Create new contact
                $contactData = [
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'email' => $email,
                ];
                if ($phoneE164 !== null) {
                    $contactData['phoneNumber'] = $phoneE164;
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

        // ── Build activity note from form responses ─────────────────
        $noteLines = [];
        $noteLines[] = "Dotazník pro copywritery";
        $noteLines[] = "Vyplněno: {$timestamp}";
        $noteLines[] = "";

        $disciplineSection = true;
        $disciplineHeaderDone = false;

        for ($col = $formStartIdx; $col <= $formEndIdx; $col++) {
            $header = $headers[$col] ?? '';
            $value = trim($values[$col] ?? '');

            if ($value === '') {
                continue;
            }

            $disciplineName = extractDisciplineName($header);

            if ($disciplineName !== null) {
                // Discipline rating (1-5)
                if (!$disciplineHeaderDone) {
                    $noteLines[] = "── Copy disciplíny (sebehodnocení 1-5) ──";
                    $disciplineHeaderDone = true;
                }
                $noteLines[] = "{$disciplineName}: {$value}";
            } else {
                // Text question — add separator after disciplines block
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

        // ── Build internal evaluation notes (one per person) ────────────
        $sheetUrl = "https://docs.google.com/spreadsheets/d/{$sheetId}/";
        $internalNotes = []; // ['Dáška' => 'text', ...]

        foreach ($headers as $idx => $header) {
            $colName = trim($header);
            if (!in_array($colName, $internalCols, true)) {
                continue;
            }
            $value = trim($values[$idx] ?? '');
            if ($value !== '') {
                $internalNotes[$colName] = $value;
            }
        }

        if ($execute && $contactId !== null) {
            // Activity 1: Form responses
            $result = $anabix->createActivity(
                (int) $contactId,
                'Dotazník: Copywriter',
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

            // Activities 2+: Internal evaluation notes (one per person)
            foreach ($internalNotes as $person => $noteText) {
                $internalBody = $noteText . "\n--\nzdroj: {$sheetUrl}\n--";

                $result = $anabix->createActivity(
                    (int) $contactId,
                    "Poznámka {$person}",
                    $internalBody,
                    'note',
                    null, // current timestamp
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

            // Preview internal notes
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
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}

finish:

output("=== Summary ===");
output("Total rows:         {$report['total_rows']}");
output("Contacts found:     {$report['contacts_found']}");
output("Contacts created:   {$report['contacts_created']}");
output("Activities created: {$report['activities_created']}");
output("Skipped (no email): {$report['skipped_no_email']}");
output("Failed:             {$report['failed']}");

if (!empty($report['errors'])) {
    output("");
    output("Errors:");
    foreach ($report['errors'] as $err) {
        output("  - {$err}");
    }
}

$logger->info("Form import completed", $report);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
