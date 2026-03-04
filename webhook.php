<?php

/**
 * Webhook receiver for Ecomail events.
 *
 * Ecomail sends a POST request when a contact subscribes/unsubscribes.
 * Payload format:
 * {
 *   "payload": {
 *     "email": "some@email.cz",
 *     "status": "SUBSCRIBED",
 *     "listId": 1,
 *     "campaignId": null
 *   }
 * }
 *
 * Endpoint: POST /webhook.php
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
    http_response_code(500);
    echo json_encode(['error' => 'Server not configured']);
    exit;
}
loadEnv($envFile);

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$logger = new Logger(__DIR__ . '/storage/logs');

// Parse request body
$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);

if ($body === null) {
    $logger->error("Webhook: invalid JSON body", ['raw' => $rawBody]);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Validate webhook secret if configured
$secret = env('WEBHOOK_SECRET');
if ($secret !== '') {
    $signature = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    if ($signature !== $secret) {
        $logger->warning("Webhook: invalid secret");
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

$logger->info("Webhook received", ['body' => $body]);

// Extract payload
$payload = $body['payload'] ?? $body;
$email = $payload['email'] ?? '';
$status = $payload['status'] ?? '';
$listId = (int) ($payload['listId'] ?? env('ECOMAIL_LIST_ID', '1'));

// Only process new subscriptions
if (strtoupper($status) !== 'SUBSCRIBED') {
    $logger->info("Webhook: ignoring non-subscribe event", ['status' => $status, 'email' => $email]);
    echo json_encode(['status' => 'ignored', 'reason' => 'not a subscribe event']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $logger->warning("Webhook: invalid email", ['email' => $email]);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email']);
    exit;
}

// Initialize clients
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

$groupIds = array_filter(array_map('intval', explode(',', env('ANABIX_GROUP_IDS', ''))));

$report = [
    'status' => 'ok',
    'created' => 0,
    'updated' => 0,
    'failed' => 0,
    'errors' => [],
];

try {
    // Check if already synced
    if ($syncState->isSynced($email)) {
        $logger->info("Webhook: contact already synced", ['email' => $email]);
        echo json_encode(['status' => 'ok', 'message' => 'already synced']);
        exit;
    }

    // Fetch full subscriber data from Ecomail
    $subscriber = $ecomail->getSubscriber($listId, $email);

    if ($subscriber === null) {
        // Use minimal data from webhook payload
        $subscriber = [
            'email' => $email,
            'name' => $payload['name'] ?? '',
            'surname' => $payload['surname'] ?? '',
        ];
    }

    $contactData = Transformer::toAnabixContact($subscriber);

    // Check if contact exists in Anabix
    $existingContact = $anabix->findContactByEmail($email);

    if ($existingContact !== null) {
        $contactId = $existingContact['idContact'] ?? $existingContact['id'] ?? null;
        if ($contactId) {
            $result = $anabix->updateContact((int) $contactId, $contactData);
            if ($result !== null) {
                $report['updated'] = 1;
            } else {
                $report['failed'] = 1;
                $report['errors'][] = "Failed to update: {$email}";
            }
        }
    } else {
        $result = $anabix->createContact($contactData);
        if ($result !== null) {
            $report['created'] = 1;

            $contactId = $result['idContact'] ?? $result['id'] ?? $result['data']['idContact'] ?? null;
            if ($contactId) {
                foreach ($groupIds as $groupId) {
                    $anabix->addContactToGroup((int) $contactId, $groupId);
                }
            }
        } else {
            $report['failed'] = 1;
            $report['errors'][] = "Failed to create: {$email}";
        }
    }

    $syncState->markSynced(strtolower($email));
    $syncState->save();

} catch (Throwable $e) {
    $report['status'] = 'error';
    $report['errors'][] = $e->getMessage();
    $logger->error("Webhook sync failed", [
        'message' => $e->getMessage(),
        'email' => $email,
    ]);
}

$logger->info("Webhook sync completed", $report);

header('Content-Type: application/json');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
