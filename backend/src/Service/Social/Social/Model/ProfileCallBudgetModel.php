<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

use App\Service\Foundation\Clock\Support\DeadlineFit;

/** How many AppView profile calls one pass may spend, each asking for up to a page of actors. */
final readonly class ProfileCallBudgetModel
{
    /** The guarded fetcher's max_duration, the longest one call can take. */
    public const int CALL_SECONDS = 20;

    private function __construct(public int $calls)
    {
    }

    public static function calls(int $count): self
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('A profile call budget cannot be negative.');
        }

        return new self($count);
    }

    /** The whole calls that end before $deadline, at most $maxCalls: none once less than one fits. */
    public static function fittingBefore(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $maxCalls): self
    {
        return self::calls(min($maxCalls, DeadlineFit::wholeUnits($deadline, $now, self::CALL_SECONDS)));
    }

    #[\NoDiscard]
    public function spending(int $calls): self
    {
        return new self(max(0, $this->calls - $calls));
    }

    public function isEmpty(): bool
    {
        return $this->calls === 0;
    }
}
