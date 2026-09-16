<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * What one rate-limit-aware round of calls produced: either completed outcomes
 * (with whether any 429 was seen along the way) or a deferral with the wait to
 * apply. A worker call retried to exhaustion stays a failure inside $outcomes,
 * carrying its RetryableProviderException (#947).
 */
final readonly class RateLimitedResult
{
    /**
     * @param list<CompletionOutcome> $outcomes
     */
    private function __construct(
        public array $outcomes,
        public bool $rateLimitObserved,
        public float $deferSeconds,
        private bool $deferred,
    ) {
    }

    /**
     * @param list<CompletionOutcome> $outcomes
     */
    public static function completed(array $outcomes, bool $rateLimitObserved): self
    {
        return new self($outcomes, $rateLimitObserved, 0.0, false);
    }

    public static function deferred(float $waitSeconds): self
    {
        return new self([], true, $waitSeconds, true);
    }

    public function isDeferred(): bool
    {
        return $this->deferred;
    }
}
