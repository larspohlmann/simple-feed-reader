<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\Support;

/** Four bytes a token, the LLM's estimate too, over the JSON System One reads. */
final class JevTokenEstimate
{
    private const int BYTES_PER_TOKEN = 4;

    public static function ofJson(mixed $value): int
    {
        return intdiv(\strlen(SystemOneJson::encode($value)), self::BYTES_PER_TOKEN) + 1;
    }

    private function __construct()
    {
    }
}
