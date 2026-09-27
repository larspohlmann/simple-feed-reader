<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * A rate-limited provider call its caller will not wait out: it defers for waitSeconds() instead (#947).
 * Named apart from App\Service\RateLimit\Exception\RateLimitedException, our own limiter's refusal.
 */
final class ProviderRateLimitedException extends \RuntimeException
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
