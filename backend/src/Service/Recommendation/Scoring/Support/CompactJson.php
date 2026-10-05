<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Support;

/** Compact UTF-8 with slashes and non-ASCII as they are: the bytes System One reads and bills. */
final class CompactJson
{
    public static function encode(mixed $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    private function __construct()
    {
    }
}
