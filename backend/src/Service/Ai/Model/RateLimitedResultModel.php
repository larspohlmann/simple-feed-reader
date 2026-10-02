<?php

declare(strict_types=1);

namespace App\Service\Ai\Model;

use App\Service\Ai\RateLimitedOutcome\RateLimitedOutcomeInterface;

/**
 * One rate-limit-aware round: completed outcomes (and whether a 429 was seen) or a deferral with its wait. A call
 * retried to exhaustion stays a failure in $outcomes, carrying its RetryableProviderException.
 *
 * @template-covariant TOutcome of RateLimitedOutcomeInterface
 */
final readonly class RateLimitedResultModel
{
    /**
     * @param list<TOutcome> $outcomes
     */
    private function __construct(
        public array $outcomes,
        public bool $rateLimitObserved,
        public float $deferSeconds,
        private bool $deferred,
    ) {
    }

    /**
     * @template TCompleted of RateLimitedOutcomeInterface
     *
     * @param list<TCompleted> $outcomes
     *
     * @return self<TCompleted>
     */
    public static function completed(array $outcomes, bool $rateLimitObserved): self
    {
        return new self($outcomes, $rateLimitObserved, 0.0, false);
    }

    /** @return self<never> */
    public static function deferred(float $waitSeconds): self
    {
        return new self([], true, $waitSeconds, true);
    }

    public function isDeferred(): bool
    {
        return $this->deferred;
    }
}
