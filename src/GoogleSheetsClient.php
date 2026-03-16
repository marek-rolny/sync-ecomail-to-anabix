<?php

/**
 * Client for reading data from public Google Sheets.
 * Downloads sheet as CSV and parses rows.
 *
 * Requires: Sheet must be shared as "Anyone with the link can view".
 */
class GoogleSheetsClient
{
    private string $spreadsheetId;
    private string $gid;
    private Logger $logger;

    public function __construct(string $spreadsheetId, string $gid, Logger $logger)
    {
        $this->spreadsheetId = $spreadsheetId;
        $this->gid = $gid;
        $this->logger = $logger;
    }

    /**
     * Fetch all rows from the Google Sheet.
     *
     * @return array[] Array of associative arrays keyed by header names
     */
    public function fetchRows(): array
    {
        $url = "https://docs.google.com/spreadsheets/d/{$this->spreadsheetId}/export?format=csv&gid={$this->gid}";

        $this->logger->info("Fetching Google Sheet", [
            'spreadsheet' => $this->spreadsheetId,
            'gid' => $this->gid,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("Google Sheets curl error", ['error' => $error]);
            throw new RuntimeException("Failed to fetch Google Sheet: {$error}");
        }

        if ($httpCode !== 200) {
            $this->logger->error("Google Sheets HTTP error", ['http_code' => $httpCode]);
            throw new RuntimeException("Google Sheets returned HTTP {$httpCode}. Is the sheet publicly shared?");
        }

        if (empty($response)) {
            $this->logger->warning("Google Sheet returned empty response");
            return [];
        }

        return $this->parseCsv($response);
    }

    /**
     * Parse CSV string into array of associative arrays.
     * First row is used as header.
     */
    private function parseCsv(string $csv): array
    {
        $rows = [];
        $lines = str_getcsv($csv, "\n");

        if (empty($lines)) {
            return [];
        }

        // First line is header
        $headers = str_getcsv($lines[0]);
        $headers = array_map('trim', $headers);

        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }

            $values = str_getcsv($line);

            // Skip rows with fewer columns than headers
            if (count($values) < count($headers)) {
                $values = array_pad($values, count($headers), '');
            }

            $row = [];
            foreach ($headers as $idx => $header) {
                $row[$header] = trim($values[$idx] ?? '');
            }

            $rows[] = $row;
        }

        $this->logger->info("Parsed Google Sheet rows", ['count' => count($rows)]);

        return $rows;
    }
}
