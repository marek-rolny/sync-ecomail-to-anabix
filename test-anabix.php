<?php

/**
 * Diagnostic script: test Anabix API connectivity step by step.
 *
 * Usage: open in browser or run via CLI
 *   https://app.web71.cz/automatizace/test-anabix.php
 *   php test-anabix.php
 */

set_time_limit(60);
ini_set('max_execution_time', '60');

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    ini_set('output_buffering', '0');
    ini_set('zlib.output_compression', '0');
    if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
    }
    while (ob_get_level()) {
        ob_end_flush();
    }
    ob_implicit_flush(true);
}

require_once __DIR__ . '/src/env.php';

// ── Load .env ──────────────────────────────────────────────────────────

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    echo "FAIL: .env file not found\n";
    exit(1);
}
loadEnv($envFile);

echo "=== Anabix API Diagnostic ===\n\n";

// ── Step 1: Check config ───────────────────────────────────────────────

$apiUrl = env('ANABIX_API_URL');
$username = env('ANABIX_USERNAME');
$token = env('ANABIX_TOKEN');

echo "1. Config check\n";
echo "   API URL:  " . ($apiUrl !== '' ? $apiUrl : 'MISSING') . "\n";
echo "   Username: " . ($username !== '' ? substr($username, 0, 3) . '***' : 'MISSING') . "\n";
echo "   Token:    " . ($token !== '' ? substr($token, 0, 4) . '***' : 'MISSING') . "\n";

if ($apiUrl === '' || $username === '' || $token === '') {
    echo "\nFAIL: Missing required config. Check .env file.\n";
    exit(1);
}
echo "   => OK\n\n";

// ── Step 2: DNS + connectivity ─────────────────────────────────────────

echo "2. DNS + connectivity\n";
$host = parse_url($apiUrl, PHP_URL_HOST);
$ip = gethostbyname($host);
echo "   Host: {$host}\n";
echo "   IP:   {$ip}\n";
if ($ip === $host) {
    echo "   => FAIL: DNS resolution failed\n\n";
} else {
    echo "   => OK\n\n";
}

// ── Step 3: Raw API call — contacts.getAll page 1 ──────────────────────

echo "3. API call: contacts.getAll (page 1, limit test)\n";

$payload = json_encode([
    'username' => $username,
    'token' => $token,
    'requestType' => 'contacts',
    'requestMethod' => 'getAll',
    'data' => ['page' => 1],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['json' => $payload],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
]);

$start = microtime(true);
$body = curl_exec($ch);
$elapsed = round(microtime(true) - $start, 2);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "   HTTP code:    {$httpCode}\n";
echo "   cURL error:   " . ($curlError ?: 'none') . "\n";
echo "   Response time: {$elapsed}s\n";
echo "   Response size: " . strlen($body) . " bytes\n";

if ($curlError) {
    echo "   => FAIL: cURL error\n\n";
    exit(1);
}

if ($httpCode < 200 || $httpCode >= 300) {
    echo "   => FAIL: HTTP error\n";
    echo "   Response: " . substr($body, 0, 500) . "\n\n";
    exit(1);
}

$response = json_decode($body, true);
if ($response === null) {
    echo "   => FAIL: invalid JSON\n";
    echo "   Response: " . substr($body, 0, 500) . "\n\n";
    exit(1);
}

// Check for API-level error
$isError = (isset($response['error']) && $response['error'])
    || (isset($response['status']) && strtoupper($response['status']) === 'ERROR');

if ($isError) {
    $msg = $response['message'] ?? $response['data'] ?? json_encode($response);
    echo "   => FAIL: API error — {$msg}\n\n";
    exit(1);
}

// Count contacts in response
$data = $response['data'] ?? $response;
$count = is_array($data) ? count($data) : 0;
echo "   Contacts on page 1: ~{$count} items\n";
echo "   => OK\n\n";

// Show first contact (redacted)
if (is_array($data) && !empty($data)) {
    $first = reset($data);
    if (is_array($first)) {
        echo "4. Sample contact (first on page 1)\n";
        $show = [];
        foreach (['idContact', 'id', 'name', 'surname', 'email', 'idOrganization', 'changedDate'] as $key) {
            if (isset($first[$key])) {
                $val = $first[$key];
                // Redact email partially
                if ($key === 'email' && strpos($val, '@') !== false) {
                    $parts = explode('@', $val);
                    $val = substr($parts[0], 0, 2) . '***@' . $parts[1];
                }
                $show[$key] = $val;
            }
        }
        echo "   " . json_encode($show, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        echo "   Available keys: " . implode(', ', array_keys($first)) . "\n\n";
    }
}

echo "=== All checks passed ===\n\n";

// ── Step 5: Pagination smoke test (offset+limit, 3 pages) ────────────────
echo "5. Pagination test (offset+limit, 3 pages of 50)\n\n";

$testLimit = 50;
$allSeenIds = [];
$paginationOk = true;

for ($testPage = 0; $testPage < 3; $testPage++) {
    $testOffset = $testPage * $testLimit;
    $testData = [
        'limit' => $testLimit,
        'offset' => $testOffset,
    ];
    $testPayload = json_encode([
        'username' => $username,
        'token' => $token,
        'requestType' => 'contacts',
        'requestMethod' => 'getAll',
        'data' => $testData,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['json' => $testPayload],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $start = microtime(true);
    $pageBody = curl_exec($ch);
    $pageElapsed = round(microtime(true) - $start, 2);
    $pageHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $pageResponse = json_decode($pageBody, true);

    echo "   offset={$testOffset}, limit={$testLimit}: HTTP {$pageHttpCode}, {$pageElapsed}s, " . strlen($pageBody) . " bytes\n";

    if (!is_array($pageResponse)) {
        echo "   => Invalid JSON\n\n";
        $paginationOk = false;
        continue;
    }

    // Extract contacts
    $pageData = $pageResponse['data'] ?? [];
    $contacts = [];
    if (is_array($pageData) && !empty($pageData)) {
        $firstItem = reset($pageData);
        if (is_array($firstItem)) {
            $contacts = array_values($pageData);
        }
    }

    echo "   Contacts: " . count($contacts) . "\n";

    // Show first 3 IDs
    $ids = [];
    foreach (array_slice($contacts, 0, 3) as $c) {
        $ids[] = $c['idContact'] ?? '?';
    }
    echo "   First 3 IDs: " . implode(', ', $ids) . "\n";

    // Check for duplicates
    $pageIds = [];
    foreach ($contacts as $c) {
        $id = $c['idContact'] ?? null;
        if ($id !== null) $pageIds[] = (int) $id;
    }
    $dupes = array_intersect($pageIds, $allSeenIds);
    $allSeenIds = array_unique(array_merge($allSeenIds, $pageIds));

    echo "   Duplicates: " . count($dupes) . "/" . count($pageIds);
    if (count($dupes) > 0) {
        echo " *** PROBLEM: duplicate IDs detected! ***";
        $paginationOk = false;
    }
    echo "\n";
    echo "   Total unique IDs so far: " . count($allSeenIds) . "\n\n";

    usleep(300000);
}

$expected = $testLimit * 3; // 150 unique IDs expected
echo "   Result: " . count($allSeenIds) . " unique IDs (expected {$expected})\n";
if ($paginationOk && count($allSeenIds) === $expected) {
    echo "   => PASS: offset+limit pagination works correctly\n";
} else {
    echo "   => FAIL: pagination is broken!\n";
}

echo "\n=== Pagination test done ===\n\n";

// ── Step 6: Dump revisionInfo structure ─────────────────────────────────
echo "6. RevisionInfo structure (first contact)\n";
if (is_array($data) && !empty($data)) {
    $first = reset($data);
    $revInfo = $first['revisionInfo'] ?? null;
    if ($revInfo === null) {
        echo "   revisionInfo: NOT PRESENT\n";
    } elseif (is_array($revInfo)) {
        echo "   revisionInfo keys: " . implode(', ', array_keys($revInfo)) . "\n";
        echo "   " . json_encode($revInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "   revisionInfo (scalar): " . var_export($revInfo, true) . "\n";
    }
    // Also check for top-level timestamp fields
    $tsFields = ['changedDate', 'dateModified', 'dateCreated', 'modifiedAt', 'createdAt', 'updatedTimestamp', 'createdTimestamp'];
    $found = [];
    foreach ($tsFields as $f) {
        if (isset($first[$f])) {
            $found[$f] = $first[$f];
        }
    }
    echo "   Top-level timestamp fields: " . (empty($found) ? 'NONE' : json_encode($found)) . "\n";
}
echo "\n";

// ── Step 7: Test offset limit — does changedSince or fullInfo cause the 1500 cap? ──
echo "7. Offset limit test (offset=1500)\n\n";

$tests = [
    'without changedSince, without fullInfo' => [],
    'with changedSince, without fullInfo' => ['changedSince' => '2000-01-01T00:00:00+00:00'],
    'without changedSince, with fullInfo' => ['fullInfo' => 1],
    'with changedSince, with fullInfo' => ['changedSince' => '2000-01-01T00:00:00+00:00', 'fullInfo' => 1],
];

foreach ($tests as $label => $extra) {
    $testData = array_merge(['limit' => 10, 'offset' => 1500], $extra);
    $testPayload = json_encode([
        'username' => $username,
        'token' => $token,
        'requestType' => 'contacts',
        'requestMethod' => 'getAll',
        'data' => $testData,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['json' => $testPayload],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $start = microtime(true);
    $testBody = curl_exec($ch);
    $elapsed = round(microtime(true) - $start, 2);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $testResponse = json_decode($testBody, true);
    $contactCount = 0;
    if (is_array($testResponse)) {
        $td = $testResponse['data'] ?? [];
        if (is_array($td)) {
            $firstItem = reset($td);
            $contactCount = is_array($firstItem) ? count($td) : 0;
        }
    }

    echo "   {$label}:\n";
    echo "     HTTP {$httpCode}, {$elapsed}s, {$contactCount} contacts\n";

    usleep(500000);
}

echo "\n=== Offset limit test done ===\n";
