<?php

/**
 * Tracks which contacts have been synced to avoid duplicates.
 * Stores state as a JSON file with synced email addresses and last sync time.
 */
class SyncState
{
    private string $stateFile;
    private array $state;

    public function __construct(string $stateDir)
    {
        $stateDir = rtrim($stateDir, '/');
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0755, true);
        }

        $this->stateFile = "{$stateDir}/sync-state.json";
        $this->load();
    }

    private function load(): void
    {
        if (file_exists($this->stateFile)) {
            $json = file_get_contents($this->stateFile);
            $this->state = json_decode($json, true) ?: [];
        } else {
            $this->state = [
                'synced_emails' => [],
                'last_sync' => null,
            ];
        }
    }

    public function isSynced(string $email): bool
    {
        return in_array(strtolower($email), $this->state['synced_emails'] ?? [], true);
    }

    public function markSynced(string $email): void
    {
        $email = strtolower($email);
        if (!in_array($email, $this->state['synced_emails'], true)) {
            $this->state['synced_emails'][] = $email;
        }
    }

    public function getLastSync(): ?string
    {
        return $this->state['last_sync'] ?? null;
    }

    public function updateLastSync(): void
    {
        $this->state['last_sync'] = date('c');
    }

    public function save(): void
    {
        file_put_contents(
            $this->stateFile,
            json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    public function getSyncedCount(): int
    {
        return count($this->state['synced_emails'] ?? []);
    }
}
