<?php

/**
 * Manages sync checkpoints for resumable operations.
 *
 * Saves progress to disk so a sync can resume after a crash/timeout
 * without re-processing already-sent data.
 *
 * Each sync type (contacts, activities, sheets) uses its own checkpoint file.
 * Checkpoints are atomic (write to temp file, then rename).
 *
 * Usage:
 *   $cp = new CheckpointManager(__DIR__ . '/storage/state');
 *   $state = $cp->load('contacts-sync');  // null if no checkpoint
 *   // ... process data ...
 *   $cp->save('contacts-sync', ['offset' => 500, 'batch' => 2, 'seen_count' => 490]);
 *   // ... when done ...
 *   $cp->clear('contacts-sync');
 */
class CheckpointManager
{
    private string $stateDir;

    public function __construct(string $stateDir)
    {
        $this->stateDir = rtrim($stateDir, '/');
        if (!is_dir($this->stateDir)) {
            mkdir($this->stateDir, 0755, true);
        }
    }

    /**
     * Save a checkpoint atomically.
     *
     * @param string $key   Checkpoint identifier (e.g. 'contacts-sync')
     * @param array  $data  Arbitrary state to persist
     */
    public function save(string $key, array $data): void
    {
        $data['_checkpoint_time'] = date('Y-m-d H:i:s');
        $file = $this->path($key);
        $tmp = $file . '.tmp';

        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        rename($tmp, $file);
    }

    /**
     * Load a checkpoint, or null if none exists.
     *
     * @param string $key         Checkpoint identifier
     * @param int    $maxAgeHours Ignore checkpoints older than this (0 = no limit)
     * @return array|null  The saved state, or null
     */
    public function load(string $key, int $maxAgeHours = 24): ?array
    {
        $file = $this->path($key);
        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            return null;
        }

        // Expire old checkpoints
        if ($maxAgeHours > 0 && isset($data['_checkpoint_time'])) {
            $cpTime = strtotime($data['_checkpoint_time']);
            if ($cpTime !== false && (time() - $cpTime) > $maxAgeHours * 3600) {
                $this->clear($key);
                return null;
            }
        }

        return $data;
    }

    /**
     * Remove a checkpoint (call after successful completion).
     */
    public function clear(string $key): void
    {
        $file = $this->path($key);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    /**
     * Check if a checkpoint exists (without loading it).
     */
    public function exists(string $key): bool
    {
        return file_exists($this->path($key));
    }

    private function path(string $key): string
    {
        // Sanitize key for filesystem
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        return $this->stateDir . '/checkpoint_' . $safe . '.json';
    }
}
