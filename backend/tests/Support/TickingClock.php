<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Clock\ClockInterface;

/**
 * A clock that moves on by a fixed step at every reading. MockClock freezes
 * time, which cannot tell one call apart from three; this one makes the
 * number of readings observable in what was written, so a test can prove
 * that something happened once per unit of work rather than once per firing.
 */
final class TickingClock implements ClockInterface
{
    private int $readings = 0;

    public function __construct(
        private readonly \DateTimeImmutable $start,
        private readonly int $stepSeconds,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        $elapsed = $this->stepSeconds * $this->readings;
        $this->readings++;

        return $this->start->modify(sprintf('+%d seconds', $elapsed));
    }

    public function sleep(float|int $seconds): void
    {
        // Nothing to wait for: this clock only advances when it is read.
    }

    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        throw new \LogicException('TickingClock has no time zone to change.');
    }
}
