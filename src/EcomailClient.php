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

    // ── Subscriber list & cleanup (GDPR) ───────────────────────────────

    /**
     * Fetch all subscriber emails from the configured Ecomail list.
     *
     * Uses GET /lists/{listId}/subscribers (paginated).
     *
     * @return string[]  Array of lowercase email addresses
     */
    public function getAllSubscriberEmails(): array
    {
        $emails = [];
        $page = 1;

        while (true) {
            $response = $this->get("/lists/{$this->listId}/subscribers", ['page' => $page]);

            if ($response === null) {
                $this->logger->error("Failed to fetch subscribers page", ['page' => $page]);
                return $emails;
            }

            $subscribers = $response['subscriber'] ?? $response['data'] ?? $response ?? [];

            if (empty($subscribers) || !is_array($subscribers)) {
                break;
            }

            foreach ($subscribers as $sub) {
                $email = strtolower(trim($sub['email'] ?? ''));
                if ($email !== '') {
                    $emails[] = $email;
                }
            }

            $this->logger->debug("Fetched Ecomail subscribers page", [
                'page' => $page,
                'count' => count($subscribers),
                'total_so_far' => count($emails),
            ]);

            // No more pages
            if (count($subscribers) < 100) {
                break;
            }

            $page++;
            usleep(300000); // 300ms rate limit courtesy
        }

        return $emails;
    }

    /**
     * Permanently delete a subscriber from Ecomail (all lists).
     *
     * Uses DELETE /subscribers/{email}/delete
     *
     * @return bool  True if deleted successfully
     */
    public function deleteSubscriber(string $email): bool
    {
        $encoded = urlencode($email);
        $response = $this->httpRequest('DELETE', "/subscribers/{$encoded}/delete");

        if ($response === null) {
            $this->logger->error("Failed to delete subscriber", ['email' => $email]);
            return false;
        }

        return true;
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
     * Get aggregate stats for a campaign (total sends, opens, clicks, etc.).
     *
     * Uses GET /campaigns/{campaignId}/stats
     *
     * @return array|null  Stats array or null on failure
     */
    public function getCampaignStats(int $campaignId): ?array
    {
        return $this->get("/campaigns/{$campaignId}/stats");
    }

    /**
     * Get per-subscriber stats for a campaign.
     *
     * Uses GET /campaigns/{campaignId}/stats-detail
     * Response: { "subscribers": { "email@x.com": { "open": 2, "send": 1, ... } }, "total": N, "per_page": 100 }
     *
     * @return array  Map of email => { open: int, send: int, click: int, ... }
     */
    public function getCampaignStatsDetail(int $campaignId, array $params = []): array
    {
        $allSubscribers = [];
        $page = 1;

        while (true) {
            $queryParams = array_merge($params, ['page' => $page, 'per_page' => 100]);
            $response = $this->get("/campaigns/{$campaignId}/stats-detail", $queryParams);

            if ($response === null) {
                break;
            }

            $subscribers = $response['subscribers'] ?? [];

            $this->logger->debug("getCampaignStatsDetail response", [
                'campaignId' => $campaignId,
                'page' => $page,
                'subscriber_count' => count($subscribers),
                'total' => $response['total'] ?? null,
            ]);

            if (empty($subscribers) || !is_array($subscribers)) {
                break;
            }

            foreach ($subscribers as $email => $stats) {
                $allSubscribers[$email] = $stats;
            }

            // Check pagination
            $nextPageUrl = $response['next_page_url'] ?? null;
            $perPage = $response['per_page'] ?? 100;

            if ($nextPageUrl === null || count($subscribers) < $perPage) {
                break;
            }

            $page++;
            usleep(300000);
        }

        return $allSubscribers;
    }

    /**
     * Get campaign log — individual events (sends, opens, clicks, bounces, etc.).
     *
     * Uses GET /campaigns/log with campaign_id filter.
     * Response: { "campaign_log": [ { "id", "campaign_id", "event", "email", ... }, ... ] }
     *
     * Possible event types: send, open, click, hard_bounce, soft_bounce,
     *                       out_of_band, unsub, spam, spam_complaint.
     *
     * @param int      $campaignId  Campaign to fetch events for
     * @param string[] $events      Event types to filter (empty = all)
     * @return array[]  List of event records
     */
    public function getCampaignLog(int $campaignId, array $events = []): array
    {
        $allEvents = [];
        $page = 1;

        while (true) {
            $queryParams = [
                'campaign_id' => $campaignId,
                'per_page' => 100,
                'page' => $page,
                'sort_by' => 'occured_at',
                'sort_dir' => 'desc',
            ];

            if (!empty($events)) {
                $queryParams['events[]'] = implode(',', $events);
            }

            $response = $this->get('/campaigns/log', $queryParams);

            if ($response === null) {
                break;
            }

            $logs = $response['campaign_log'] ?? $response['data'] ?? [];

            $this->logger->debug("getCampaignLog response", [
                'campaignId' => $campaignId,
                'page' => $page,
                'log_count' => count($logs),
                'response_keys' => array_keys($response),
            ]);

            if (empty($logs) || !is_array($logs)) {
                break;
            }

            foreach ($logs as $log) {
                if (is_array($log)) {
                    $allEvents[] = $log;
                }
            }

            if (count($logs) < 100) {
                break;
            }

            $page++;
            usleep(300000);
        }

        return $allEvents;
    }

    /**
     * Get subscriber events for a campaign (backward-compatible wrapper).
     *
     * Tries campaign log first (individual events), falls back to stats-detail.
     *
     * @return array[]  List of event arrays with 'email' and 'event' keys
     */
    public function getCampaignEvents(int $campaignId): array
    {
        // Primary: campaign log — gives individual events
        $events = $this->getCampaignLog($campaignId);

        if (!empty($events)) {
            return $events;
        }

        // Fallback: stats-detail — convert per-subscriber counts to events
        $subscribers = $this->getCampaignStatsDetail($campaignId);

        if (empty($subscribers)) {
            return [];
        }

        $events = [];
        foreach ($subscribers as $email => $stats) {
            foreach ($stats as $eventType => $count) {
                if (is_int($count) && $count > 0) {
                    $events[] = [
                        'email' => $email,
                        'event' => $eventType,
                        'count' => $count,
                    ];
                }
            }
        }

        return $events;
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
     * Get all subscribers from the configured list.
     *
     * Uses GET /lists/{listId}/subscribers with pagination.
     *
     * @return array[]  List of subscriber arrays
     */
    public function getSubscribers(): array
    {
        $all = [];
        $page = 1;

        while (true) {
            $response = $this->get("/lists/{$this->listId}/subscribers", [
                'per_page' => 100,
                'page' => $page,
            ]);

            if ($response === null) {
                break;
            }

            $subscribers = $response['subscriber'] ?? $response['subscribers'] ?? $response['data'] ?? [];

            $this->logger->debug("getSubscribers page", [
                'page' => $page,
                'count' => count($subscribers),
                'response_keys' => array_keys($response),
            ]);

            if (empty($subscribers) || !is_array($subscribers)) {
                break;
            }

            foreach ($subscribers as $sub) {
                if (is_array($sub)) {
                    $all[] = $sub;
                }
            }

            if (count($subscribers) < 100) {
                break;
            }

            $page++;
            usleep(300000);
        }

        return $all;
    }

    // ── Subscriber logs (for activity sync) ──────────────────────────

    /**
     * Get email (campaign) log for a subscriber.
     *
     * Uses GET /subscribers/{email}/email-log
     * Returns campaign events: send, open, click, hard_bounce, soft_bounce,
     *                          out_of_band, unsub, spam, spam_complaint.
     *
     * @return array[]  List of event records
     */
    public function getSubscriberEmailLog(string $email, array $params = []): array
    {
        // /subscribers/{email}/email-log returns 403 on some accounts.
        // Use /campaigns/log?email=... instead — same data, works universally.
        $allEvents = [];
        $page = 1;

        while (true) {
            $queryParams = array_merge($params, [
                'email' => $email,
                'per_page' => 100,
                'page' => $page,
                'sort_by' => 'occured_at',
                'sort_dir' => 'desc',
            ]);

            $response = $this->get('/campaigns/log', $queryParams);

            if ($response === null) {
                break;
            }

            // API returns: {"campaign_log":[{...}]}
            $logs = $response['campaign_log'] ?? $response['data'] ?? [];

            if (empty($logs) || !is_array($logs)) {
                break;
            }

            foreach ($logs as $log) {
                if (is_array($log)) {
                    $allEvents[] = $log;
                }
            }

            if (count($logs) < 100) {
                break;
            }

            $page++;
            usleep(200000);
        }

        return $allEvents;
    }

    /**
     * Get automation (pipeline) log for a subscriber.
     *
     * Uses GET /subscribers/{email}/automation-log
     * Returns automation events sorted by timestamp descending.
     *
     * @return array[]  List of event records
     */
    public function getSubscriberAutomationLog(string $email, array $params = []): array
    {
        $encoded = urlencode($email);
        $allEvents = [];
        $page = 1;

        while (true) {
            $queryParams = array_merge($params, [
                'per_page' => 100,
                'page' => $page,
                'sort_by' => 'timestamp',
                'sort_dir' => 'desc',
            ]);

            $response = $this->get("/subscribers/{$encoded}/automation-log", $queryParams);

            if ($response === null) {
                break;
            }

            $logs = $response['automation_log'] ?? $response['pipeline_log'] ?? $response['data'] ?? [];

            if (empty($logs) || !is_array($logs)) {
                break;
            }

            foreach ($logs as $log) {
                if (is_array($log)) {
                    $allEvents[] = $log;
                }
            }

            if (count($logs) < 100) {
                break;
            }

            $page++;
            usleep(200000);
        }

        return $allEvents;
    }

    /**
     * Get tracker events for a subscriber (web visits, basket, purchase, etc.).
     *
     * Uses GET /subscribers/{email}/events
     * Response: {"current_page":1,"data":[{"id","email","category","action","label","property","value","timestamp"}],...}
     *
     * @return array[]  List of event records
     */
    public function getSubscriberEvents(string $email, array $params = []): array
    {
        $encoded = urlencode($email);
        $allEvents = [];
        $page = 1;

        while (true) {
            $queryParams = array_merge($params, [
                'per_page' => 100,
                'page' => $page,
            ]);

            $response = $this->get("/subscribers/{$encoded}/events", $queryParams);

            if ($response === null) {
                break;
            }

            $events = $response['data'] ?? [];

            if (empty($events) || !is_array($events)) {
                break;
            }

            foreach ($events as $event) {
                if (is_array($event)) {
                    $allEvents[] = $event;
                }
            }

            $lastPage = $response['last_page'] ?? null;
            if ($lastPage !== null && $page >= $lastPage) {
                break;
            }
            if (count($events) < 100) {
                break;
            }

            $page++;
            usleep(200000);
        }

        return $allEvents;
    }

    // ── HTTP methods ──────────────────────────────────────────────────

    /**
     * Public debug wrapper for GET requests — returns raw parsed response
     * including HTTP status code and raw body for debugging.
     */
    public function debugGet(string $endpoint, array $params = []): ?array
    {
        $url = $this->baseUrl . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'key: ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            '_debug_http_code' => $httpCode,
            '_debug_curl_error' => $error ?: null,
            '_debug_url' => $url,
            '_debug_body' => mb_substr($body ?: '', 0, 1000),
            '_debug_parsed' => json_decode($body ?: '', true),
        ];
    }

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
        } elseif ($method === 'DELETE') {
            $options[CURLOPT_URL] = $url;
            $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
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
            // 404 on subscriber log endpoints = subscriber has no records (treat as empty)
            if ($httpCode === 404 && preg_match('#/subscribers/.+/(email-log|automation-log|events)#', $endpoint)) {
                return [];
            }
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
