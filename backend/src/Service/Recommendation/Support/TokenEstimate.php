<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Support;

/** Four bytes a token, both engines' estimate of what a provider bills. */
final class TokenEstimate
{
    private const int BYTES_PER_TOKEN = 4;

    public static function of(string $text): int
    {
        return self::ofLength(\strlen($text));
    }

    public static function ofLength(int $bytes): int
    {
        return intdiv($bytes, self::BYTES_PER_TOKEN) + 1;
    }

    private function __construct()
    {
    }
}
