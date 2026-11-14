<?php

declare(strict_types=1);

namespace App\Service\Clock;

use Psr\Clock\ClockInterface;

/**
 * "Now" as Doctrine may persist it: the `datetime` type writes wall-clock values unconverted (CLAUDE.md, "Datetimes
 * are stored as naive UTC"). Inject this instead of ClockInterface wherever a "now" is about to be persisted.
 */
final readonly class NaiveUtcClock
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
