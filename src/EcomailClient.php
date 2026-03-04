<?php

/**
 * Client for Ecomail API v2.
 * Fetches subscribers from a specific list.
 *
 * API docs: https://ecomailczv2.docs.apiary.io/
 * Auth: key header with API key
 * Base URL: https://api2.ecomail.cz
 */
class EcomailClient
{
    private string $apiKey;
    private string $baseUrl;
    private Logger $logger;

    public function __construct(string $apiKey, string $baseUrl, Logger $logger)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger = $logger;
    }

    /**
     * Fetch all subscribers from a list, paginating through all pages.
     *
     * @return array List of subscriber arrays with keys: email, name, surname, status, etc.
     */
    public function getAllSubscribers(int $listId, int $pageSize = 20): array
    {
        $allSubscribers = [];
        $page = 1;

        while (true) {
            $this->logger->info("Fetching Ecomail subscribers", [
                'list_id' => $listId,
                'page' => $page,
            ]);

            $response = $this->get("/lists/{$listId}/subscribers", ['page' => $page]);

            if ($response === null) {
                $this->logger->error("Failed to fetch subscribers page", ['page' => $page]);
                break;
            }

            // The API returns either data directly or nested under 'data'
            $subscribers = $response['data'] ?? $response;

            if (!is_array($subscribers) || empty($subscribers)) {
                break;
            }

            foreach ($subscribers as $subscriber) {
                // Normalize: subscribers may be nested under 'subscriber_data'
                $data = $subscriber['subscriber_data'] ?? $subscriber;
                $allSubscribers[] = $data;
            }

            // Check pagination
            $lastPage = $response['last_page'] ?? $response['meta']['last_page'] ?? $page;
            if ($page >= $lastPage) {
                break;
            }

            $page++;
        }

        $this->logger->info("Fetched total Ecomail subscribers", ['count' => count($allSubscribers)]);
        return $allSubscribers;
    }

    /**
     * Fetch a single subscriber by email from a list.
     */
    public function getSubscriber(int $listId, string $email): ?array
    {
        $email = urlencode($email);
        $response = $this->get("/lists/{$listId}/subscriber/{$email}");
        return $response;
    }

    /**
     * GET request to Ecomail API.
     */
    private function get(string $endpoint, array $params = []): ?array
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
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("Ecomail API curl error", ['error' => $error, 'url' => $url]);
            return null;
        }

        if ($httpCode === 429) {
            $this->logger->warning("Ecomail API rate limit hit, waiting 60s");
            sleep(60);
            return $this->get($endpoint, $params);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error("Ecomail API error", [
                'http_code' => $httpCode,
                'response' => $responseBody,
                'url' => $url,
            ]);
            return null;
        }

        return json_decode($responseBody, true);
    }
}
