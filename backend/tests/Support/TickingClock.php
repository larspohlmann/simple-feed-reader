<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Clock\ClockInterface;

/**
 * Advances a fixed step at every reading, so the number of readings shows in what was written; MockClock's frozen time
 * cannot tell one call from three.
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
