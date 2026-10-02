<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\Support;

/** Compact UTF-8 with slashes and non-ASCII as they are: the bytes System One reads and bills. */
final class SystemOneJson
{
    public static function encode(mixed $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    private function __construct()
    {
    }
}
