<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * How a tick handles a provider rate limit, decided by its driver. The worker
 * owns its process, so it blocks and retries in-tick within a budget; the poll
 * and sweep drivers run inside a bounded web request, so they never block and
 * defer to a later tick instead (#947).
 */
final readonly class RetryPlan
{
    /** Waits before the 1st, 2nd, 3rd retry when the provider gives no Retry-After. */
    private const array BACKOFF_SECONDS = [1.0, 2.0, 4.0];

    private const int MAX_RETRIES = 3;

    /** Stays under Strato's 240 s cgi-fcgi cap with room for the call itself. */
    private const float WORKER_BUDGET_SECONDS = 120.0;

    private function __construct(private bool $blocks)
    {
    }

    public static function forDriver(TickDriver $driver): self
    {
        return new self(TickDriver::Worker === $driver);
    }

    public function blocks(): bool
    {
        return $this->blocks;
    }

    public function maxRetries(): int
    {
        return self::MAX_RETRIES;
    }

    public function budgetSeconds(): float
    {
        return self::WORKER_BUDGET_SECONDS;
    }

    public function waitSecondsFor(int $retryIndex, ?int $retryAfterSeconds): float
    {
        if (null !== $retryAfterSeconds) {
            return (float) $retryAfterSeconds;
        }

        return self::BACKOFF_SECONDS[$retryIndex] ?? self::BACKOFF_SECONDS[array_key_last(self::BACKOFF_SECONDS)];
    }
}
