<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Clock\ClockInterface;

/**
 * A fixed number of good readings, then a failure for good: the cheapest way to make work throw at a chosen point and
 * prove its cleanup sits in a `finally`. The good readings let the work reach the state whose cleanup matters.
 */
final class ThrowingClock implements ClockInterface
{
    public const string MESSAGE = 'Simulated: the clock is unreadable.';

    private int $readings = 0;

    public function __construct(
        private readonly int $healthyReadings = 0,
        private readonly \DateTimeImmutable $reading = new \DateTimeImmutable('2026-08-14 00:00:00'),
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        if ($this->readings++ < $this->healthyReadings) {
            return $this->reading;
        }

        throw new \RuntimeException(self::MESSAGE);
    }

    public function sleep(float|int $seconds): void
    {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        throw new \LogicException('ThrowingClock has no time zone to change.');
    }
}
