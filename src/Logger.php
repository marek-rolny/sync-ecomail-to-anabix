<?php

class Logger
{
    private string $logDir;

    public function __construct(string $logDir)
    {
        $this->logDir = rtrim($logDir, '/');
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('DEBUG', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $date = date('Y-m-d');
        $time = date('Y-m-d H:i:s');
        $file = "{$this->logDir}/sync-{$date}.log";

        $entry = "[{$time}] [{$level}] {$message}";
        if (!empty($context)) {
            $entry .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        $entry .= PHP_EOL;

        file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
    }
}
