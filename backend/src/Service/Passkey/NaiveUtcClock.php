<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use Psr\Clock\ClockInterface;

/**
 * Wraps the injected PSR clock to hand back a value Doctrine can persist
 * directly: Doctrine's `datetime` type writes the wall-clock value with no
 * timezone conversion, so any non-UTC clock must be normalised to UTC
 * *before* it reaches an entity (see CLAUDE.md's "Datetimes are stored as
 * naive UTC" gotcha).
 *
 * One home for what `AttestationVerifier`, `AssertionVerifier` and
 * `PasskeyOffer` each used to carry as their own identical private
 * `nowAsNaiveUtc()` method — inject this instead of `ClockInterface`
 * directly wherever a "now" is about to be persisted.
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
