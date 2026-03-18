<?php

/**
 * Client for Anabix CRM API.
 * Creates/updates contacts and manages group assignments.
 *
 * API: POST to https://{ACCOUNT}.anabix.cz/api
 * Auth: username + token in request body
 * Format: JSON with requestType, requestMethod, data
 *
 * Reference: https://github.com/rotten77/anabix-api
 */
class AnabixClient
{
    private string $user;
    private string $token;
    private string $apiUrl;
    private Logger $logger;

    public function __construct(string $user, string $token, string $apiUrl, Logger $logger)
    {
        $this->user = $user;
        $this->token = $token;
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->logger = $logger;
    }

    /**
     * Search for a contact by email.
     *
     * @return array|null Contact data or null if not found
     */
    public function findContactByEmail(string $email): ?array
    {
        $response = $this->request('contacts', 'getAll', [
            'criteria' => ['email' => $email],
        ]);

        if ($response === null) {
            $this->logger->warning("findContactByEmail: API returned null", ['email' => $email]);
            return null;
        }

        $this->logger->debug("findContactByEmail: raw response", [
            'email' => $email,
            'response_keys' => is_array($response) ? array_keys($response) : gettype($response),
            'response' => $response,
        ]);

        // Try to extract contact(s) from the response.
        // Anabix API may return data in several formats:
        //   {"error":false, "data": {"123": {contact...}, "456": {contact...}}}
        //   {"error":false, "data": [{contact...}]}
        //   {"error":false, "data": {single contact...}}
        //   {"error":false, "123": {contact...}}  (contacts at top level)
        //   or other variations

        // 1) Try $response['data']
        $data = $response['data'] ?? null;

        if (is_array($data) && !empty($data)) {
            $first = reset($data);
            // If data contains nested arrays (list of contacts), return first
            if (is_array($first)) {
                return $first;
            }
            // If data itself looks like a single contact (has idContact or email key)
            if (isset($data['idContact']) || isset($data['id']) || isset($data['email'])) {
                return $data;
            }
            // Data contains scalar values keyed by field names - might be a contact
            return $data;
        }

        // 2) Try top-level response (minus error/message keys)
        $filtered = array_filter($response, function ($value, $key) {
            return !in_array($key, ['error', 'message', 'data'], true) && is_array($value);
        }, ARRAY_FILTER_USE_BOTH);

        if (!empty($filtered)) {
            $first = reset($filtered);
            if (is_array($first)) {
                return $first;
            }
        }

        $this->logger->warning("findContactByEmail: contact not found", [
            'email' => $email,
        ]);

        return null;
    }

    /**
     * Create a new contact in Anabix.
     *
     * @param array $contactData Fields: firstName, lastName, email, phone, etc.
     * @return array|null Created contact data or null on failure
     */
    public function createContact(array $contactData): ?array
    {
        $this->logger->info("Creating Anabix contact", ['email' => $contactData['email'] ?? 'unknown']);

        $response = $this->request('contacts', 'create', $contactData);

        if ($response === null) {
            $this->logger->error("Failed to create Anabix contact", ['data' => $contactData]);
            return null;
        }

        return $response;
    }

    /**
     * Update an existing contact.
     *
     * @param int $contactId The Anabix contact ID
     * @param array $contactData Fields to update
     * @return array|null Updated contact data or null on failure
     */
    public function updateContact(int $contactId, array $contactData): ?array
    {
        $contactData['id'] = $contactId;

        $this->logger->info("Updating Anabix contact", ['id' => $contactId]);

        $response = $this->request('contacts', 'update', $contactData);

        if ($response === null) {
            $this->logger->error("Failed to update Anabix contact", ['id' => $contactId]);
            return null;
        }

        return $response;
    }

    /**
     * Add a contact to a group.
     *
     * @param int $contactId The Anabix contact ID
     * @param int $groupId The Anabix group ID
     */
    public function addContactToGroup(int $contactId, int $groupId): ?array
    {
        $this->logger->info("Adding contact to group", ['contact' => $contactId, 'group' => $groupId]);

        return $this->request('contacts', 'addToGroup', [
            'idContact' => $contactId,
            'idGroup' => $groupId,
        ]);
    }

    /**
     * Create an activity on a contact.
     *
     * @param int $contactId The Anabix contact ID
     * @param string $title Activity title
     * @param string $body Activity description
     * @param string $type Activity type (e.g. 'note', 'email', 'call')
     * @param string|null $timestamp Activity date (Y-m-d H:i:s), defaults to now
     * @param int|null $idUser Activity owner user ID (e.g. 5 for Robot Karel)
     */
    public function createActivity(
        int $contactId,
        string $title,
        string $body,
        string $type = 'note',
        ?string $timestamp = null,
        ?int $idUser = null
    ): ?array {
        $data = [
            'idContact' => $contactId,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'timestamp' => $timestamp ?? date('Y-m-d H:i:s'),
        ];

        if ($idUser !== null) {
            $data['idUser'] = $idUser;
        }

        $this->logger->info("Creating Anabix activity", [
            'contact' => $contactId,
            'title' => $title,
            'idUser' => $idUser,
        ]);

        $response = $this->request('activities', 'create', $data);

        if ($response === null) {
            $this->logger->error("Failed to create Anabix activity", [
                'contact' => $contactId,
                'title' => $title,
            ]);
        }

        return $response;
    }

    /** @var int Counter for debug output - show details for first N API calls */
    private int $debugCallCount = 0;
    private const DEBUG_FIRST_N_CALLS = 3;

    /**
     * Send a request to the Anabix API.
     */
    private function request(string $requestType, string $requestMethod, array $data = []): ?array
    {
        $this->debugCallCount++;
        $showDebug = $this->debugCallCount <= self::DEBUG_FIRST_N_CALLS;

        // Anabix API expects multipart/form-data with a 'json' field
        $payload = json_encode([
            'username' => $this->user,
            'token' => $this->token,
            'requestType' => $requestType,
            'requestMethod' => $requestMethod,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);

        if ($showDebug) {
            fwrite(STDERR, "[DEBUG] API request #{$this->debugCallCount}: {$requestType}/{$requestMethod} -> {$this->apiUrl}\n");
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['json' => $payload],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            if ($showDebug) {
                fwrite(STDERR, "[DEBUG] API cURL ERROR: {$error}\n");
            }
            $this->logger->error("Anabix API curl error", [
                'error' => $error,
                'type' => $requestType,
                'method' => $requestMethod,
            ]);
            return null;
        }

        if ($showDebug) {
            fwrite(STDERR, "[DEBUG] API response (HTTP {$httpCode}): " . mb_substr($responseBody, 0, 500) . "\n");
        }

        $response = json_decode($responseBody, true);

        if ($response === null) {
            if ($showDebug) {
                fwrite(STDERR, "[DEBUG] API invalid JSON!\n");
            }
            $this->logger->error("Anabix API invalid JSON response", [
                'http_code' => $httpCode,
                'response' => $responseBody,
            ]);
            return null;
        }

        // Check for API-level error in the response
        if (isset($response['error']) && $response['error']) {
            if ($showDebug) {
                fwrite(STDERR, "[DEBUG] API error flag: " . json_encode($response['error']) . " message: " . ($response['message'] ?? '') . "\n");
            }
            $this->logger->error("Anabix API returned error", [
                'error' => $response['error'],
                'message' => $response['message'] ?? '',
                'type' => $requestType,
                'method' => $requestMethod,
            ]);
            return null;
        }

        return $response;
    }
}
