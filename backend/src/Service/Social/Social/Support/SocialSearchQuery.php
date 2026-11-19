<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

final class SocialSearchQuery
{
    public const int MAX_LENGTH = 512;

    public static function normalized(string $query): string
    {
        return trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
    }

    public static function isValid(string $query): bool
    {
        return '' !== $query && mb_strlen($query) <= self::MAX_LENGTH;
    }

    private function __construct()
    {
    }
}
