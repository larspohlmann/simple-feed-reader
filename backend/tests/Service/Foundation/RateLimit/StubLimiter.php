<?php

declare(strict_types=1);

namespace App\Tests\Service\RateLimit;

use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\Reservation;

/**
 * A limiter whose single consume() always returns the verdict it was built with.
 */
final readonly class StubLimiter implements LimiterInterface
{
    public function __construct(private RateLimit $verdict)
    {
    }

    public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
    {
        throw new \LogicException('The guard consumes, it does not reserve.');
    }

    public function consume(int $tokens = 1): RateLimit
    {
        return $this->verdict;
    }

    public function reset(): void
    {
    }
}
