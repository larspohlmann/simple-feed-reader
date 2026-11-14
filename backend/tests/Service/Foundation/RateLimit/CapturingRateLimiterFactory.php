<?php

declare(strict_types=1);

namespace App\Tests\Service\RateLimit;

use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Records the key it was asked to create a limiter for, so a test can assert
 * how the guard derives it (per user id versus per client IP).
 */
final class CapturingRateLimiterFactory implements RateLimiterFactoryInterface
{
    public ?string $capturedKey = null;

    public function __construct(private readonly LimiterInterface $limiter)
    {
    }

    public function create(?string $key = null): LimiterInterface
    {
        $this->capturedKey = $key;

        return $this->limiter;
    }
}
