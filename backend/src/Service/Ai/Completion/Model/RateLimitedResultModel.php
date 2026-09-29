<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion\Model;

/**
 * One rate-limit-aware round: completed outcomes (and whether a 429 was seen) or a deferral with its wait. A call
 * retried to exhaustion stays a failure in $outcomes, carrying its RetryableProviderException.
 */
final readonly class RateLimitedResultModel
{
    /**
     * @param list<CompletionOutcomeModel> $outcomes
     */
    private function __construct(
        public array $outcomes,
        public bool $rateLimitObserved,
        public float $deferSeconds,
        private bool $deferred,
    ) {
    }

    /**
     * @param list<CompletionOutcomeModel> $outcomes
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
