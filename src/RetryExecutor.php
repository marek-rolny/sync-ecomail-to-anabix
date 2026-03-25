<?php

/**
 * Generic retry executor with exponential backoff.
 *
 * Reusable across any integration that needs retry logic:
 *   Anabix API, Ecomail API, Google Sheets, Asana, etc.
 *
 * Usage:
 *   $retry = new RetryExecutor(maxAttempts: 3, baseDelay: 2.0, logger: $logger);
 *   $result = $retry->execute(
 *       fn() => $httpClient->post('/endpoint', $data),
 *       'Ecomail subscribe-bulk'
 *   );
 */
class RetryExecutor
{
    private int $maxAttempts;
    private float $baseDelay;
    private ?Logger $logger;

    /**
     * @param int    $maxAttempts  Total attempts (1 = no retry, 3 = up to 2 retries)
     * @param float  $baseDelay   Base delay in seconds (doubles each retry: 2, 4, 8...)
     * @param Logger|null $logger Optional logger for retry events
     */
    public function __construct(int $maxAttempts = 3, float $baseDelay = 2.0, ?Logger $logger = null)
    {
        $this->maxAttempts = max(1, $maxAttempts);
        $this->baseDelay = $baseDelay;
        $this->logger = $logger;
    }

    /**
     * Execute a callable with retry on failure.
     *
     * The callable should:
     *   - Return a value on success
     *   - Throw RetryableException for transient errors (will be retried)
     *   - Throw any other Exception for permanent errors (will NOT be retried)
     *
     * @param callable $fn          The operation to execute
     * @param string   $description Human-readable description for logging
     * @return mixed   The return value of $fn on success
     * @throws \Exception  The last exception if all attempts fail, or a non-retryable exception
     */
    public function execute(callable $fn, string $description = 'operation')
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                return $fn();
            } catch (RetryableException $e) {
                $lastException = $e;

                if ($attempt < $this->maxAttempts) {
                    $delay = $this->baseDelay * (2 ** ($attempt - 1));
                    $this->log('warning', "Retryable error in {$description} (attempt {$attempt}/{$this->maxAttempts}), waiting {$delay}s", [
                        'error' => $e->getMessage(),
                        'attempt' => $attempt,
                        'max_attempts' => $this->maxAttempts,
                        'delay' => $delay,
                    ]);
                    usleep((int) ($delay * 1_000_000));
                } else {
                    $this->log('error', "All {$this->maxAttempts} attempts failed for {$description}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            // Non-RetryableException propagates immediately (not caught here)
        }

        throw $lastException;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->$level($message, $context);
        }
    }
}

/**
 * Marker exception: throw this to signal that the operation should be retried.
 */
class RetryableException extends \RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
