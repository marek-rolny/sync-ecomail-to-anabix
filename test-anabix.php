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

// ── Step 5: Pagination test — fetch pages 1-3 ──────────────────────────
echo "5. Pagination test (pages 1-3, with changedSince=2000-01-01)\n\n";

$allSeenIds = [];
for ($testPage = 1; $testPage <= 3; $testPage++) {
    $testData = [
        'page' => $testPage,
        'changedSince' => '2000-01-01T00:00:00+00:00',
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

    echo "   Page {$testPage}: HTTP {$pageHttpCode}, {$pageElapsed}s, " . strlen($pageBody) . " bytes\n";

    if (!is_array($pageResponse)) {
        echo "   => Invalid JSON, skipping\n\n";
        continue;
    }

    // Show response top-level keys
    $topKeys = array_keys($pageResponse);
    echo "   Response keys: " . implode(', ', $topKeys) . "\n";

    // Show pagination metadata
    $pagesVal = $pageResponse['pages'] ?? $pageResponse['totalPages'] ?? 'N/A';
    $pageVal = $pageResponse['page'] ?? 'N/A';
    echo "   pages={$pagesVal}, page={$pageVal}\n";

    // Extract contacts
    $pageData = $pageResponse['data'] ?? [];
    if (is_array($pageData) && !empty($pageData)) {
        $firstItem = reset($pageData);
        if (is_array($firstItem)) {
            $contacts = array_values($pageData);
        } elseif (isset($pageData['idContact'])) {
            $contacts = [$pageData];
        } else {
            $contacts = [];
        }
    } else {
        $contacts = [];
    }

    echo "   Contacts: " . count($contacts) . "\n";

    // Show first 5 IDs
    $ids = [];
    foreach (array_slice($contacts, 0, 5) as $c) {
        $ids[] = $c['idContact'] ?? $c['id'] ?? '?';
    }
    echo "   First 5 IDs: " . implode(', ', $ids) . "\n";

    // Check for duplicates with previous pages
    $pageIds = [];
    foreach ($contacts as $c) {
        $id = $c['idContact'] ?? $c['id'] ?? null;
        if ($id !== null) $pageIds[] = (int) $id;
    }
    $dupes = array_intersect($pageIds, $allSeenIds);
    echo "   Duplicates with prev pages: " . count($dupes) . "/" . count($pageIds) . "\n";
    $allSeenIds = array_merge($allSeenIds, $pageIds);
    echo "   Total unique IDs so far: " . count(array_unique($allSeenIds)) . "\n\n";

    usleep(300000); // rate limit
}

echo "=== Pagination test done ===\n\n";

// ── Step 6: Try different pagination parameters ─────────────────────────
echo "6. Pagination parameter discovery\n\n";

$paginationTests = [
    'offset=200'        => ['offset' => 200],
    'offset=1'          => ['offset' => 1],
    'limit=5'           => ['limit' => 5],
    'perPage=5'         => ['perPage' => 5],
    'count=5'           => ['count' => 5],
    'pageSize=5'        => ['pageSize' => 5],
    'start=200'         => ['start' => 200],
    'from=200'          => ['from' => 200],
    'page=2,perPage=200' => ['page' => 2, 'perPage' => 200],
    'page=2,limit=200'  => ['page' => 2, 'limit' => 200],
    'page=2,count=200'  => ['page' => 2, 'count' => 200],
];

foreach ($paginationTests as $label => $extraParams) {
    $testData = array_merge(['page' => 1], $extraParams);
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

    $pageBody = curl_exec($ch);
    $pageHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $pageResponse = json_decode($pageBody, true);
    $pageData = $pageResponse['data'] ?? [];
    $contactCount = 0;
    $firstId = '?';

    if (is_array($pageData) && !empty($pageData)) {
        $firstItem = reset($pageData);
        if (is_array($firstItem)) {
            $contactCount = count($pageData);
            $firstId = $firstItem['idContact'] ?? $firstItem['id'] ?? '?';
        }
    }

    $bytes = strlen($pageBody);
    $status = $pageResponse['status'] ?? 'N/A';
    echo "   {$label}: {$contactCount} contacts, firstId={$firstId}, {$bytes}B, status={$status}\n";

    usleep(300000);
}

echo "\n=== Parameter discovery done ===\n";
