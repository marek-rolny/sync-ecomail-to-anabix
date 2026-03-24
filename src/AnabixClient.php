<?php

/**
 * Client for Anabix CRM API.
 *
 * API: POST to https://{ACCOUNT}.anabix.cz/api
 * Auth: username + token in request body
 * Format: multipart/form-data with 'json' field containing JSON payload
 *
 * Supports:
 *  - contacts: getAll (paginated, delta), get (detail)
 *  - lists: getAll, getMembers
 *  - organizations: get (single + parallel bulk via curl_multi)
 *  - activities: create
 *
 * Retry: up to 3 attempts with exponential backoff on transient errors
 * (5xx, 429, 408, cURL failures).
 */
class AnabixClient
{
    private string $user;
    private string $token;
    private string $apiUrl;
    private Logger $logger;

    private const MAX_RETRIES = 3;
    private const RETRY_BASE_DELAY = 2; // seconds — exponential: 2s, 4s, 8s

    public function __construct(string $user, string $token, string $apiUrl, Logger $logger)
    {
        $this->user = $user;
        $this->token = $token;
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->logger = $logger;
    }

    // ── Contacts ──────────────────────────────────────────────────────

    /**
     * Fetch contacts from Anabix, optionally filtered by changedSince.
     *
     * @param string|null $changedSince  ISO 8601 or Y-m-d H:i:s — only contacts changed after this time
     * @return array  Flat list of contact arrays
     */
    /**
     * Fetch contacts page by page as a Generator to avoid loading all into memory.
     *
     * Yields arrays of contacts (one array per page).
     *
     * @param string|null $changedSince  ISO 8601 or Y-m-d H:i:s
     * @return \Generator<int, array[], void, void>  Yields [contact, contact, ...] per page
     */
    public function getContactsPaginated(?string $changedSince = null, bool $fullInfo = false): \Generator
    {
        $limit = 100;
        $offset = 0;
        $page = 0;
        $totalFetched = 0;
        $seenIds = [];

        while (true) {
            $page++;
            $data = ['limit' => $limit, 'offset' => $offset];
            if ($changedSince !== null) {
                $data['changedSince'] = $changedSince;
            }
            if ($fullInfo) {
                $data['fullInfo'] = 1;
            }

            // After first successful page, use only 1 retry — Anabix returns
            // HTTP 500 when offset exceeds total contacts, so retrying wastes time.
            $retries = $totalFetched > 0 ? 1 : self::MAX_RETRIES;
            $this->logger->info("Requesting contacts page {$page}", ['offset' => $offset, 'limit' => $limit]);
            $response = $this->request('contacts', 'getAll', $data, $retries);

            if ($response === null) {
                if ($totalFetched > 0) {
                    // We already have data — treat this as probable end of data.
                    // Anabix API is known to return HTTP 500 when offset exceeds total contacts.
                    $this->logger->warning("Failed to fetch contacts at offset {$offset}, but already have {$totalFetched} contacts — treating as end of data", [
                        'offset' => $offset,
                        'limit' => $limit,
                        'total_fetched' => $totalFetched,
                    ]);
                } else {
                    $this->logger->error("Failed to fetch contacts on first page", ['offset' => $offset, 'limit' => $limit]);
                }
                break;
            }

            $contacts = $this->extractList($response);

            if (empty($contacts)) {
                $this->logger->info("No more contacts returned", ['offset' => $offset]);
                break;
            }

            // Detect duplicates as a safety check
            $pageIds = [];
            foreach ($contacts as $c) {
                $id = $c['idContact'] ?? $c['id'] ?? null;
                if ($id !== null) {
                    $pageIds[] = (int) $id;
                }
            }

            if (!empty($pageIds)) {
                $newIds = array_diff($pageIds, array_keys($seenIds));
                foreach ($pageIds as $id) {
                    $seenIds[$id] = true;
                }
                if (empty($newIds)) {
                    $this->logger->info("Pagination loop detected, stopping", [
                        'offset' => $offset,
                        'total_unique' => count($seenIds),
                    ]);
                    break;
                }
            }

            $totalFetched += count($contacts);

            $this->logger->info("Fetched contacts", [
                'page' => $page,
                'offset' => $offset,
                'count' => count($contacts),
                'total' => $totalFetched,
                'unique_ids' => count($seenIds),
            ]);

            yield $contacts;

            // If we got fewer than the limit, we've reached the end
            if (count($contacts) < $limit) {
                $this->logger->info("Last page reached (partial page)", [
                    'offset' => $offset,
                    'count' => count($contacts),
                    'total' => $totalFetched,
                ]);
                break;
            }

            $offset += $limit;

            // Rate limiting — longer pause to avoid overloading Anabix
            usleep(500000);
        }
    }

    /**
     * Fetch all contacts into memory (legacy convenience wrapper).
     *
     * WARNING: For large datasets, use getContactsPaginated() instead.
     */
    public function getContacts(?string $changedSince = null): array
    {
        $all = [];
        foreach ($this->getContactsPaginated($changedSince) as $page) {
            foreach ($page as $contact) {
                $all[] = $contact;
            }
        }
        return $all;
    }

    /**
     * Fetch a single contact by ID (full detail).
     */
    public function getContact(int $contactId): ?array
    {
        $response = $this->request('contacts', 'get', ['idContact' => $contactId]);

        if ($response === null) {
            return null;
        }

        // Response may contain the contact directly or nested under 'data'
        return $response['data'] ?? $response;
    }

    /**
     * Search for a contact by email.
     *
     * Used by sync-sheets.php for Google Sheets → Anabix activity sync.
     */
    public function findContactByEmail(string $email): ?array
    {
        $response = $this->request('contacts', 'getAll', [
            'criteria' => ['email' => $email],
        ]);

        if ($response === null) {
            return null;
        }

        $contacts = $this->extractList($response);

        if (!empty($contacts)) {
            return reset($contacts);
        }

        // Fallback: single contact might be directly in 'data'
        $data = $response['data'] ?? null;
        if (is_array($data) && (isset($data['idContact']) || isset($data['email']))) {
            return $data;
        }

        return null;
    }

    // ── Lists ─────────────────────────────────────────────────────────

    /**
     * Fetch all lists (groups/categories) from Anabix.
     *
     * @return array  List of ['idList' => ..., 'title' => ...] arrays
     */
    public function getLists(): array
    {
        $response = $this->request('lists', 'getAll');

        if ($response === null) {
            return [];
        }

        return $this->extractList($response);
    }

    /**
     * Fetch member contact IDs for a specific list.
     *
     * @return int[]  Array of contact IDs belonging to the list
     */
    public function getListMembers(int $listId): array
    {
        $response = $this->request('lists', 'getMembers', ['idList' => $listId]);

        if ($response === null) {
            return [];
        }

        // Response may be a flat list of IDs or list of objects
        $data = $this->extractList($response);
        $ids = [];

        foreach ($data as $item) {
            if (is_numeric($item)) {
                $ids[] = (int) $item;
            } elseif (is_array($item)) {
                $id = $item['idContact'] ?? $item['id'] ?? null;
                if ($id !== null) {
                    $ids[] = (int) $id;
                }
            }
        }

        return $ids;
    }

    // ── Organizations ─────────────────────────────────────────────────

    /**
     * Fetch a single organization by ID.
     */
    public function getOrganization(int $orgId): ?array
    {
        $response = $this->request('organizations', 'get', ['idOrganization' => $orgId]);

        if ($response === null) {
            return null;
        }

        return $response['data'] ?? $response;
    }

    /**
     * Fetch multiple organizations in parallel using curl_multi.
     *
     * @param int[] $orgIds         Organization IDs to fetch
     * @param int   $concurrency    Max parallel requests
     * @return array  Map of orgId => organization data
     */
    public function getOrganizationsParallel(array $orgIds, int $concurrency = 20): array
    {
        if (empty($orgIds)) {
            return [];
        }

        $results = [];
        $chunks = array_chunk($orgIds, $concurrency);

        foreach ($chunks as $chunk) {
            $multiHandle = curl_multi_init();
            $handles = [];

            foreach ($chunk as $orgId) {
                $payload = json_encode([
                    'username' => $this->user,
                    'token' => $this->token,
                    'requestType' => 'organizations',
                    'requestMethod' => 'get',
                    'data' => ['idOrganization' => $orgId],
                ], JSON_UNESCAPED_UNICODE);

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $this->apiUrl,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => ['json' => $payload],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_NOSIGNAL => 1,
                ]);

                curl_multi_add_handle($multiHandle, $ch);
                $handles[$orgId] = $ch;
            }

            // Execute all requests
            do {
                $status = curl_multi_exec($multiHandle, $active);
                curl_multi_select($multiHandle);
            } while ($active > 0 && $status === CURLM_OK);

            // Collect results
            foreach ($handles as $orgId => $ch) {
                $body = curl_multi_getcontent($ch);
                $response = json_decode($body, true);

                if ($response !== null && empty($response['error'])) {
                    $org = $response['data'] ?? $response;
                    if (is_array($org) && !empty($org)) {
                        $results[$orgId] = $org;
                    }
                }

                curl_multi_remove_handle($multiHandle, $ch);
                curl_close($ch);
            }

            curl_multi_close($multiHandle);

            $this->logger->info("Fetched organizations batch", [
                'requested' => count($chunk),
                'received' => count(array_intersect_key($results, array_flip($chunk))),
            ]);
        }

        return $results;
    }

    // ── Activities ────────────────────────────────────────────────────

    /**
     * Create an activity on a contact.
     *
     * Used by sync-sheets.php and activities-ecomail-to-anabix.php.
     */
    public function createActivity(
        int $contactId,
        string $title,
        string $body,
        string $type = 'note',
        ?string $timestamp = null,
        ?int $idUser = null
    ): ?array {
        if ($timestamp !== null) {
            $unixTimestamp = is_numeric($timestamp) ? (int) $timestamp : strtotime($timestamp);
        } else {
            $unixTimestamp = time();
        }

        $data = [
            'idContact' => $contactId,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'timestamp' => $unixTimestamp,
        ];

        if ($idUser !== null) {
            $data['idUser'] = $idUser;
        }

        $this->logger->info("Creating activity", [
            'contact' => $contactId,
            'type' => $type,
            'title' => $title,
        ]);

        return $this->request('activities', 'create', $data);
    }

    // ── Internal ──────────────────────────────────────────────────────

    /**
     * Extract a flat list of items from an Anabix API response.
     *
     * Anabix responses come in many shapes:
     *   {"data": {"123": {...}, "456": {...}}}
     *   {"data": [{...}, {...}]}
     *   {"123": {...}, "456": {...}}  (items at top level)
     */
    private function extractList(array $response): array
    {
        // Try 'data' key first
        $data = $response['data'] ?? null;

        if (is_array($data) && !empty($data)) {
            $first = reset($data);
            if (is_array($first)) {
                return array_values($data);
            }
            // Single item that looks like a record
            if (isset($data['idContact']) || isset($data['idList']) || isset($data['idOrganization'])) {
                return [$data];
            }
        }

        // Try common alternative keys
        foreach (['contacts', 'items', 'records', 'lists', 'members'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return array_values($response[$key]);
            }
        }

        // Try top-level (minus metadata keys)
        $filtered = array_filter($response, function ($value, $key) {
            return !in_array($key, ['error', 'message', 'data', 'status', 'page', 'pages'], true)
                && is_array($value);
        }, ARRAY_FILTER_USE_BOTH);

        if (!empty($filtered)) {
            return array_values($filtered);
        }

        return [];
    }

    /**
     * Send a request to the Anabix API with retry on transient errors.
     */
    private function request(string $requestType, string $requestMethod, array $data = [], ?int $maxRetries = null): ?array
    {
        $maxRetries = $maxRetries ?? self::MAX_RETRIES;

        $payload = json_encode([
            'username' => $this->user,
            'token' => $this->token,
            'requestType' => $requestType,
            'requestMethod' => $requestMethod,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $this->logger->debug("API request start", [
                'type' => $requestType,
                'method' => $requestMethod,
                'attempt' => "{$attempt}/{$maxRetries}",
                'data_keys' => array_keys($data),
            ]);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->apiUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ['json' => $payload],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_NOSIGNAL => 1, // Required for CURLOPT_TIMEOUT to work reliably
            ]);

            $startTime = microtime(true);
            $responseBody = curl_exec($ch);
            $elapsed = round(microtime(true) - $startTime, 2);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $this->logger->debug("API request done", [
                'type' => $requestType,
                'method' => $requestMethod,
                'http_code' => $httpCode,
                'elapsed' => "{$elapsed}s",
                'response_size' => strlen($responseBody ?: ''),
                'error' => $error ?: null,
            ]);

            // cURL transport error
            if ($error) {
                $this->logger->warning("Anabix API cURL error (attempt {$attempt}/{$maxRetries})", [
                    'error' => $error,
                    'type' => $requestType,
                    'method' => $requestMethod,
                ]);
                if ($attempt < $maxRetries) {
                    $delay = (int) pow(self::RETRY_BASE_DELAY, $attempt); // 2s, 4s, 8s
                    sleep($delay);
                    continue;
                }
                $this->logger->error("Anabix API cURL error after all retries", ['error' => $error]);
                return null;
            }

            // Transient HTTP errors — retry
            if (in_array($httpCode, [408, 429, 500, 502, 503, 504], true)) {
                $this->logger->warning("Anabix API transient HTTP error (attempt {$attempt}/{$maxRetries})", [
                    'http_code' => $httpCode,
                    'type' => $requestType,
                    'method' => $requestMethod,
                ]);
                if ($attempt < $maxRetries) {
                    $delay = (int) pow(self::RETRY_BASE_DELAY, $attempt); // 2s, 4s, 8s
                    sleep($delay);
                    continue;
                }
                $this->logger->error("Anabix API HTTP error after all retries", ['http_code' => $httpCode]);
                return null;
            }

            // Non-transient HTTP error
            if ($httpCode < 200 || $httpCode >= 300) {
                $this->logger->error("Anabix API HTTP error", [
                    'http_code' => $httpCode,
                    'response' => mb_substr($responseBody, 0, 500),
                ]);
                return null;
            }

            // Parse JSON
            $response = json_decode($responseBody, true);
            if ($response === null) {
                $this->logger->error("Anabix API invalid JSON", [
                    'response' => mb_substr($responseBody, 0, 500),
                ]);
                return null;
            }

            // API-level error
            $isError = (isset($response['error']) && $response['error'])
                || (isset($response['status']) && strtoupper($response['status']) === 'ERROR');

            if ($isError) {
                $errorMessage = $response['message'] ?? $response['data'] ?? 'Unknown error';
                $this->logger->error("Anabix API error", [
                    'message' => $errorMessage,
                    'type' => $requestType,
                    'method' => $requestMethod,
                ]);
                return null;
            }

            return $response;
        }

        return null;
    }
}
