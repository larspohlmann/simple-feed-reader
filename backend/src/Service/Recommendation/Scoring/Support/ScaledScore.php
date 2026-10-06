<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Support;

/** A model's value in [0, 1] on the run's 0–1000 score scale; a value outside it is clamped, never trusted. */
final class ScaledScore
{
    private const int SCALE = 1000;

    public static function of(float $value): int
    {
        return max(0, min(self::SCALE, (int) round($value * self::SCALE)));
    }

    private function __construct()
    {
    }
}
