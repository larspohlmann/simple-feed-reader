<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Support;

/** Four bytes a token, both engines' estimate of what a provider bills. */
final class TokenEstimate
{
    private const int BYTES_PER_TOKEN = 4;

    public static function estimateTokens(string $text): int
    {
        return self::estimateTokensForLength(\strlen($text));
    }

    public static function estimateTokensForLength(int $length): int
    {
        return intdiv($length, self::BYTES_PER_TOKEN) + 1;
    }

    private function __construct()
    {
    }
}
