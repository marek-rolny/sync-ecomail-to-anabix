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
            return null;
        }

        // Response may contain array of contacts or single contact
        $contacts = $response['data'] ?? $response;

        if (is_array($contacts) && !empty($contacts)) {
            // Return first matching contact
            return is_array(reset($contacts)) ? reset($contacts) : $contacts;
        }

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
     * Create an activity on a contact (for Phase 2).
     *
     * @param int $contactId The Anabix contact ID
     * @param string $title Activity title
     * @param string $body Activity description
     * @param string $type Activity type (e.g. 'note', 'email', 'call')
     */
    public function createActivity(int $contactId, string $title, string $body, string $type = 'note'): ?array
    {
        return $this->request('activities', 'create', [
            'idContact' => $contactId,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Send a request to the Anabix API.
     */
    private function request(string $requestType, string $requestMethod, array $data = []): ?array
    {
        // Anabix API expects multipart/form-data with a 'json' field
        $payload = json_encode([
            'username' => $this->user,
            'token' => $this->token,
            'requestType' => $requestType,
            'requestMethod' => $requestMethod,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);

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
            $this->logger->error("Anabix API curl error", [
                'error' => $error,
                'type' => $requestType,
                'method' => $requestMethod,
            ]);
            return null;
        }

        $response = json_decode($responseBody, true);

        if ($response === null) {
            $this->logger->error("Anabix API invalid JSON response", [
                'http_code' => $httpCode,
                'response' => $responseBody,
            ]);
            return null;
        }

        // Check for API-level error in the response
        if (isset($response['error']) && $response['error']) {
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
