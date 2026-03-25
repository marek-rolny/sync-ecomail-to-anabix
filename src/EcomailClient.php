<?php

/**
 * Client for Ecomail API v2.
 *
 * API docs: https://ecomailczv2.docs.apiary.io/
 * Auth: 'key' header with API key
 * Base URL: https://api2.ecomailapp.cz
 *
 * Primary operation: bulk subscribe/upsert contacts into a list.
 * Also supports reading campaigns and subscriber events for activity sync.
 */
class EcomailClient
{
    private string $apiKey;
    private string $baseUrl;
    private int $listId;
    private Logger $logger;
    private bool $triggerAutoresponders;
    private bool $resubscribe;

    public function __construct(
        string $apiKey,
        string $baseUrl,
        int $listId,
        Logger $logger,
        bool $triggerAutoresponders = false,
        bool $resubscribe = false
    ) {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->listId = $listId;
        $this->logger = $logger;
        $this->triggerAutoresponders = $triggerAutoresponders;
        $this->resubscribe = $resubscribe;
    }

    // ── Bulk subscribe (contacts sync) ────────────────────────────────

    /**
     * Bulk upsert contacts into the configured Ecomail list.
     *
     * Uses POST /lists/{listId}/subscribe-bulk.
     * Sends update_existing=true so existing contacts get updated.
     *
     * On failure, retries once. If retry also fails, splits the batch in half
     * and tries each half separately (recursive). This isolates problematic
     * contacts without losing the entire batch.
     *
     * @param array[] $subscribers  Array of subscriber payloads (from Transformer)
     * @return array  ['imported' => int, 'updated' => int, 'failed' => int, 'errors' => string[]]
     */
    public function bulkUpsertContacts(array $subscribers): array
    {
        return $this->sendBatch($subscribers, 0);
    }

    /**
     * Internal: send a batch with retry and recursive split on failure.
     *
     * @param int $depth  Recursion depth (prevents infinite splitting)
     */
    private function sendBatch(array $subscribers, int $depth): array
    {
        $result = [
            'imported' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        if (empty($subscribers)) {
            return $result;
        }

        $payload = [
            'subscriber_data' => $subscribers,
            'update_existing' => true,
            'skip_confirmation' => true,
        ];

        if ($this->triggerAutoresponders) {
            $payload['trigger_autoresponders'] = true;
        }

        if ($this->resubscribe) {
            $payload['resubscribe'] = true;
        }

        // Attempt 1
        $response = $this->post("/lists/{$this->listId}/subscribe-bulk", $payload);

        // Attempt 2 (retry) if first attempt failed
        if ($response === null) {
            $this->logger->warning("Ecomail batch failed, retrying in 5s", ['count' => count($subscribers)]);
            sleep(5);
            $response = $this->post("/lists/{$this->listId}/subscribe-bulk", $payload);
        }

        // Both attempts failed — split and retry halves
        if ($response === null) {
            // Don't split single-item batches or if we've split too deep
            if (count($subscribers) <= 1 || $depth >= 4) {
                $result['failed'] = count($subscribers);
                $emails = array_map(fn($s) => $s['email'] ?? '?', $subscribers);
                $result['errors'][] = 'Batch failed after retry: ' . implode(', ', $emails);
                $this->logger->error("Ecomail batch permanently failed", [
                    'count' => count($subscribers),
                    'emails' => $emails,
                    'depth' => $depth,
                ]);
                return $result;
            }

            $mid = (int) ceil(count($subscribers) / 2);
            $firstHalf = array_slice($subscribers, 0, $mid);
            $secondHalf = array_slice($subscribers, $mid);

            $this->logger->warning("Ecomail batch failed, splitting", [
                'original_count' => count($subscribers),
                'first_half' => count($firstHalf),
                'second_half' => count($secondHalf),
                'depth' => $depth,
            ]);

            $r1 = $this->sendBatch($firstHalf, $depth + 1);
            sleep(2);
            $r2 = $this->sendBatch($secondHalf, $depth + 1);

            return $this->mergeResults($r1, $r2);
        }

        // Success — log and parse response
        $this->logger->info("Ecomail subscribe-bulk raw response", [
            'sent' => count($subscribers),
            'response' => $response,
        ]);

        $result['imported'] = (int) ($response['inserts'] ?? $response['inserted'] ?? $response['imported'] ?? 0);
        $result['updated'] = (int) ($response['updated'] ?? $response['updates'] ?? 0);
        $result['ecomail_response'] = $response;

        $unaccounted = count($subscribers) - $result['imported'] - $result['updated'];
        if ($unaccounted > 0) {
            $this->logger->info("Ecomail subscribe-bulk result", [
                'sent' => count($subscribers),
                'new_inserts' => $result['imported'],
                'already_existing' => $unaccounted,
                'note' => 'Existing contacts are updated silently; Ecomail does not report update count',
            ]);
        }

        return $result;
    }

    private function mergeResults(array $r1, array $r2): array
    {
        return [
            'imported' => $r1['imported'] + $r2['imported'],
            'updated' => $r1['updated'] + $r2['updated'],
            'failed' => $r1['failed'] + $r2['failed'],
            'errors' => array_merge($r1['errors'], $r2['errors']),
            'ecomail_response' => $r1['ecomail_response'] ?? $r2['ecomail_response'] ?? null,
        ];
    }

    // ── Campaigns (for activity sync) ─────────────────────────────────

    /**
     * List campaigns, optionally filtered by status.
     *
     * @return array[]  List of campaign arrays
     */
    public function getCampaigns(?string $status = null): array
    {
        $params = [];
        if ($status !== null) {
            $params['status'] = $status;
        }

        $response = $this->get('/campaigns', $params);

        return $response['data'] ?? $response ?? [];
    }

    /**
     * Get a single campaign detail.
     */
    public function getCampaign(int $campaignId): ?array
    {
        return $this->get("/campaigns/{$campaignId}");
    }

    /**
     * Get subscriber events for a campaign (sends, opens, clicks, bounces, etc.).
     *
     * @return array[]  List of event arrays
     */
    public function getCampaignEvents(int $campaignId): array
    {
        $response = $this->get("/campaigns/{$campaignId}/response");

        return $response['data'] ?? $response ?? [];
    }

    /**
     * Get subscriber detail by email (for reading custom fields like anabixId).
     */
    public function getSubscriber(string $email): ?array
    {
        $encoded = urlencode($email);
        $response = $this->get("/lists/{$this->listId}/subscriber/{$encoded}");

        if ($response === null) {
            return null;
        }

        return $response['subscriber'] ?? $response['data'] ?? $response;
    }

    /**
     * Get email log for a subscriber.
     *
     * API: GET /lists/{list_id}/subscriber/{email}/email-log
     * @see https://docs.ecomail.cz/api-reference/subscribers/email-log
     *
     * Returns array of log entries with fields:
     *   campaign_id, autoresponder_id, action_id, event, msg, url,
     *   email, occured_at, mail_name
     *
     * Events: send, open, click, hard_bounce, soft_bounce,
     *         out_of_band, unsub, spam, spam_complaint
     *
     * @return array[]  List of email log entries
     */
    public function getSubscriberEmailLog(string $email): array
    {
        $encoded = urlencode($email);
        $response = $this->get("/lists/{$this->listId}/subscriber/{$encoded}/email-log");

        if ($response === null) {
            return [];
        }

        return $response['data'] ?? $response['events'] ?? $response ?? [];
    }

    // ── HTTP methods ──────────────────────────────────────────────────

    private function post(string $endpoint, array $data): ?array
    {
        return $this->httpRequest('POST', $endpoint, $data);
    }

    private function get(string $endpoint, array $params = []): ?array
    {
        return $this->httpRequest('GET', $endpoint, $params);
    }

    private function httpRequest(string $method, string $endpoint, array $data = []): ?array
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init();
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'key: ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_URL] = $url;
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
        } else {
            if (!empty($data)) {
                $url .= '?' . http_build_query($data);
            }
            $options[CURLOPT_URL] = $url;
        }

        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("Ecomail cURL error", ['error' => $error, 'url' => $url]);
            return null;
        }

        // Rate limit — wait and retry once
        if ($httpCode === 429) {
            $this->logger->warning("Ecomail rate limit hit, waiting 60s");
            sleep(60);
            return $this->httpRequest($method, $endpoint, $data);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error("Ecomail HTTP error", [
                'http_code' => $httpCode,
                'response' => $this->parseErrorMessage($responseBody),
                'endpoint' => $endpoint,
            ]);
            return null;
        }

        $response = json_decode($responseBody, true);

        if ($response === null && $responseBody !== '') {
            $this->logger->error("Ecomail invalid JSON", [
                'response' => mb_substr($responseBody, 0, 500),
            ]);
            return null;
        }

        return $response ?? [];
    }

    /**
     * Try to extract a human-readable error message from an API error body.
     */
    private function parseErrorMessage(string $body): string
    {
        $decoded = json_decode($body, true);

        if ($decoded === null) {
            return mb_substr($body, 0, 300);
        }

        // Common Ecomail error formats
        if (isset($decoded['message'])) {
            return $decoded['message'];
        }

        if (isset($decoded['error'])) {
            return is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error']);
        }

        return mb_substr($body, 0, 300);
    }
}
