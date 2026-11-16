<?php

declare(strict_types=1);

namespace App\Service\Foundation\Clock\Support;

final class DeadlineFit
{
    private function __construct()
    {
    }

    /** How many whole units of $unitSeconds end before $deadline, counted in whole seconds: none once it has passed. */
    public static function wholeUnits(\DateTimeImmutable $deadline, \DateTimeImmutable $now, float $unitSeconds): int
    {
        return (int) floor(max(0, $deadline->getTimestamp() - $now->getTimestamp()) / $unitSeconds);
    }
}
