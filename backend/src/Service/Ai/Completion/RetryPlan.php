<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion;

/**
 * How a caller handles a provider rate limit (#947): a blocking plan waits and retries within a budget,
 * a deferring plan hands the wait back for its caller to record.
 */
final readonly class RetryPlan
{
    /** Waits before the 1st, 2nd, 3rd retry when the provider gives no Retry-After. */
    private const array BACKOFF_SECONDS = [1.0, 2.0, 4.0];

    private const int MAX_RETRIES = 3;

    /** Stays under Strato's 240 s cgi-fcgi cap with room for the call itself. */
    private const float BLOCKING_BUDGET_SECONDS = 120.0;

    private function __construct(private bool $blocks)
    {
    }

    public static function blocking(): self
    {
        return new self(true);
    }

    public static function deferring(): self
    {
        return new self(false);
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
        return self::BLOCKING_BUDGET_SECONDS;
    }

    public function waitSecondsFor(int $retryIndex, ?int $retryAfterSeconds): float
    {
        if (null !== $retryAfterSeconds) {
            return (float) $retryAfterSeconds;
        }

        return self::BACKOFF_SECONDS[$retryIndex] ?? self::BACKOFF_SECONDS[array_key_last(self::BACKOFF_SECONDS)];
    }
}
