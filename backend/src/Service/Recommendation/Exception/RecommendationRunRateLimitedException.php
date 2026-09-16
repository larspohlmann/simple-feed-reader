<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Exception;

/**
 * A rate-limited call the current tick will not wait out: the advancer catches
 * it, records "retry not before now + waitSeconds" on the run, and returns at
 * once. Named apart from App\Exception\RateLimitedException, which is the HTTP
 * limiter's 429 to the client (#947).
 */
final class RecommendationRunRateLimitedException extends \RuntimeException
{
    public function __construct(private readonly float $waitSeconds)
    {
        parent::__construct(sprintf('Provider rate limited; deferring for %.0f s.', $waitSeconds));
    }

    public function waitSeconds(): float
    {
        return $this->waitSeconds;
    }
}
