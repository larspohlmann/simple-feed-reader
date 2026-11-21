<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Feed\Support;

final class RunIdText
{
    private const int MAX_DIGITS = 18;

    public static function parse(string $text): ?int
    {
        if (!ctype_digit($text) || \strlen($text) > self::MAX_DIGITS) {
            return null;
        }
        $id = (int) $text;

        return $id < 1 ? null : $id;
    }

    private function __construct()
    {
    }
}
