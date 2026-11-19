<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Social\Social\Exception\SearchQueryTooBroadException;

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

    /** @param \Closure(string): list<string> $buildQueries */
    public static function isSearchable(\Closure $buildQueries, string $query): bool
    {
        try {
            $buildQueries($query);
        } catch (SearchQueryTooBroadException) {
            return false;
        }

        return true;
    }

    private function __construct()
    {
    }
}
