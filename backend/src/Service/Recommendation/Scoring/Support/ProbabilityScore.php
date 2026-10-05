<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Support;

/** A probability on the run's 0–1000 score scale; a value outside 0–1 is clamped, never trusted. */
final class ProbabilityScore
{
    private const int SCALE = 1000;

    public static function of(float $probability): int
    {
        return max(0, min(self::SCALE, (int) round($probability * self::SCALE)));
    }

    private function __construct()
    {
    }
}
