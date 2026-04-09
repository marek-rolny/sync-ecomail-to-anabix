<?php

/**
 * Client for Web Local Business (WLB) form data API.
 *
 * Fetches form submissions via POST to the WLB API endpoint.
 * Uses stav/stav_zmenit parameters to process only new submissions.
 *
 * API: POST https://www.optimal-marketing.cz/wlb/?formular-odber
 * Auth: formular (ID) + heslo (password) in JSON body
 */
class WlbClient
{
    private string $apiUrl;
    private Logger $logger;

    public function __construct(string $apiUrl, Logger $logger)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->logger = $logger;
    }

    /**
     * Fetch new form submissions from WLB.
     *
     * Uses stav=0 to get only new (unprocessed) submissions,
     * and stav_zmenit=50 to mark them as "Vyřízený robotem" after export.
     *
     * @param int $formId WLB form ID
     * @param string $password API password for this form
     * @return array List of form submissions, each keyed by submission ID
     */
    public function getNewSubmissions(int $formId, string $password): array
    {
        $this->logger->info("WLB: fetching new submissions", ['form_id' => $formId]);

        $payload = json_encode([
            'formular' => (string) $formId,
            'heslo' => $password,
            'stav' => 0,
            'stav_zmenit' => 50,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl . '/?formular-odber',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("WLB API curl error", [
                'error' => $error,
                'form_id' => $formId,
            ]);
            return [];
        }

        if ($httpCode !== 200) {
            $this->logger->error("WLB API unexpected HTTP code", [
                'http_code' => $httpCode,
                'form_id' => $formId,
                'response' => $responseBody,
            ]);
            return [];
        }

        $response = json_decode($responseBody, true);

        if ($response === null) {
            $this->logger->error("WLB API invalid JSON response", [
                'http_code' => $httpCode,
                'response' => $responseBody,
            ]);
            return [];
        }

        // Response is an object keyed by submission IDs
        // Each value contains form fields including meta-data-* system fields
        $submissions = [];
        foreach ($response as $id => $fields) {
            $fields['_submission_id'] = $id;
            $submissions[] = $fields;
        }

        $this->logger->info("WLB: fetched submissions", [
            'form_id' => $formId,
            'count' => count($submissions),
        ]);

        return $submissions;
    }
}
