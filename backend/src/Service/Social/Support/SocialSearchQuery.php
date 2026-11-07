<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

final class SocialSearchQuery
{
    public const int MAX_LENGTH = 512;

    public static function normalized(string $query): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $query) ?? $query);
    }

    private function __construct()
    {
    }
}
