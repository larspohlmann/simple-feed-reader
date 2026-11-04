<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Clock\ClockInterface;

/** Runs an interruption at its first reading, so work that reads the clock between two steps sees it in between. */
final class InterruptingClock implements ClockInterface
{
    private bool $interrupted = false;

    /** @param \Closure(): void $interruption */
    public function __construct(
        private readonly \DateTimeImmutable $reading,
        private readonly \Closure $interruption,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        if (!$this->interrupted) {
            $this->interrupted = true;
            ($this->interruption)();
        }

        return $this->reading;
    }

    public function sleep(float|int $seconds): void
    {
        // Nothing to wait for: the reading is fixed.
    }

    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        throw new \LogicException('InterruptingClock has no time zone to change.');
    }
}
