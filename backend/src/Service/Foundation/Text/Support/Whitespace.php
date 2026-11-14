<?php

declare(strict_types=1);

namespace App\Service\Text\Support;

final class Whitespace
{
    public static function collapse(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    private function __construct()
    {
    }
}
