<?php

declare(strict_types=1);

namespace App\Service\Foundation\Clock\Support;

final class ElapsedSeconds
{
    private function __construct()
    {
    }

    public static function between(\DateTimeImmutable $start, \DateTimeImmutable $end): float
    {
        return (float) $end->format('U.u') - (float) $start->format('U.u');
    }
}
