<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * Lower-casing for the search, beyond the ASCII letters SQLite's LIKE folds on its own. The term (here) and the
 * haystack (FoldCaseFunction's SQLite UDF) must fold identically.
 */
final readonly class CaseFolding
{
    public static function fold(string $value): string
    {
        return mb_strtolower($value);
    }

    public static function isNeededFor(string $term): bool
    {
        return preg_match('/[^\x00-\x7F]/', $term) === 1;
    }
}
