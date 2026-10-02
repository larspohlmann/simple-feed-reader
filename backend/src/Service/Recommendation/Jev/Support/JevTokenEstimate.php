<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\Support;

/** The codebase's estimate, four bytes a token, over the JSON as System One reads it (UTF-8, unescaped). */
final class JevTokenEstimate
{
    private const int BYTES_PER_TOKEN = 4;

    public static function ofJson(mixed $value): int
    {
        $json = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return intdiv(\strlen($json), self::BYTES_PER_TOKEN) + 1;
    }

    private function __construct()
    {
    }
}
