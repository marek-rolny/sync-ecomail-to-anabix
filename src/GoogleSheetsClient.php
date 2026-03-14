<?php

/**
 * Client for reading data from Google Sheets via API v4.
 * Requires a Google API Key and the spreadsheet to be publicly readable.
 */
class GoogleSheetsClient
{
    private string $apiKey;
    private Logger $logger;

    public function __construct(string $apiKey, Logger $logger)
    {
        $this->apiKey = $apiKey;
        $this->logger = $logger;
    }

    /**
     * Fetch all rows from a given sheet.
     *
     * @param string $spreadsheetId The spreadsheet ID from the URL
     * @param string $sheetName The name of the sheet/tab
     * @return array[] Array of rows, each row is an array of cell values
     */
    public function getRows(string $spreadsheetId, string $sheetName): array
    {
        $range = urlencode($sheetName);
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}?key={$this->apiKey}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("Google Sheets API curl error", ['error' => $error]);
            return [];
        }

        if ($httpCode !== 200) {
            $this->logger->error("Google Sheets API HTTP error", [
                'http_code' => $httpCode,
                'response' => $responseBody,
            ]);
            return [];
        }

        $response = json_decode($responseBody, true);

        if ($response === null) {
            $this->logger->error("Google Sheets API invalid JSON", ['response' => $responseBody]);
            return [];
        }

        return $response['values'] ?? [];
    }
}
