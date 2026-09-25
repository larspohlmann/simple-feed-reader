<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Exception;

/**
 * A rate-limited provider call the tick will not wait out: the advancer records "retry not before now +
 * waitSeconds" and returns. Named apart from Service\RateLimit\Exception\RateLimitedException (#947).
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
