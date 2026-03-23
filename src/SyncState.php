<?php

/**
 * Manages sync state for delta synchronization.
 *
 * Stores the timestamp of the last successful sync run.
 * On next run, only contacts changed after (lastSync - lookbackMinutes)
 * are fetched, providing a safe overlap window.
 *
 * State is only saved when explicitly called (after a successful run).
 */
class SyncState
{
    private string $stateFile;
    private ?string $lastSync;

    public function __construct(string $stateFile)
    {
        $dir = dirname($stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->stateFile = $stateFile;
        $this->load();
    }

    private function load(): void
    {
        if (!file_exists($this->stateFile)) {
            $this->lastSync = null;
            return;
        }

        $data = json_decode(file_get_contents($this->stateFile), true);
        $this->lastSync = $data['last_sync'] ?? null;
    }

    /**
     * Get the last successful sync timestamp.
     */
    public function getLastSync(): ?string
    {
        return $this->lastSync;
    }

    /**
     * Calculate the "changed since" cutoff time.
     *
     * Decision tree:
     *  1. If $forceSince is set and valid (not in future), use it
     *  2. If lastSync exists, use (lastSync - lookbackMinutes)
     *  3. Otherwise return null (full sync)
     *
     * @param string|null $forceSince      Override timestamp (from SYNC_FORCE_SINCE env)
     * @param int         $lookbackMinutes Safety overlap window (default 60)
     * @return string|null  ISO 8601 timestamp or null for full sync
     */
    public function getChangedSince(?string $forceSince = null, int $lookbackMinutes = 60): ?string
    {
        // Option 1: forced override
        if ($forceSince !== null && $forceSince !== '') {
            $ts = strtotime($forceSince);
            if ($ts !== false && $ts <= time()) {
                return date('c', $ts);
            }
        }

        // Option 2: last sync with lookback
        if ($this->lastSync !== null) {
            $ts = strtotime($this->lastSync);
            if ($ts !== false) {
                return date('c', $ts - ($lookbackMinutes * 60));
            }
        }

        // Option 3: full sync
        return null;
    }

    /**
     * Record current time as the last sync timestamp.
     * Does NOT persist — call save() after a successful run.
     */
    public function markCompleted(): void
    {
        $this->lastSync = date('c');
    }

    /**
     * Persist the state to disk.
     * Only call this after a fully successful sync (no failures).
     */
    public function save(): void
    {
        file_put_contents(
            $this->stateFile,
            json_encode(['last_sync' => $this->lastSync], JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
