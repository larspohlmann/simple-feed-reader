<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Clock\ClockInterface;

/**
 * Hands out a fixed number of good readings and then fails for good. Reading
 * the clock is the cheapest seam a test has for making a body of work throw
 * at a chosen point, which is what proves a `finally` really is one: a
 * cleanup written as a trailing statement passes every happy-path test and
 * still leaks on the failure path (#371 final review, Finding 9). The healthy
 * readings exist so a test can let the work start -- and put the collaborator
 * under test into the state whose cleanup matters -- before the failure.
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
