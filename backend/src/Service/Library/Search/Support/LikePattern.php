<?php

declare(strict_types=1);

namespace App\Service\Search\Support;

use App\Doctrine\WordBoundaries;

/**
 * Builds the LIKE pattern for one term, with "%" and "_" escaped so user input never acts as a wildcard. The escape
 * is "!", not a backslash, which MySQL also reads as a string escape. Every query using a pattern from here MUST
 * declare ESCAPE_CHARACTER in its ESCAPE clause.
 */
final readonly class LikePattern
{
    public const string ESCAPE_CHARACTER = '!';

    public static function containing(string $term): string
    {
        return '%' . self::escape($term) . '%';
    }

    /**
     * The term padded with a space on each side, for a haystack CONCAT(' ', …, ' ') padded alike. The term first gets
     * NormalizeWordBoundariesFunction's replacement, so "E-Mail" meets the haystack's "E Mail", and "!" is already a
     * space when escape() runs.
     */
    public static function wholeWord(string $term): string
    {
        return '% ' . self::escape(WordBoundaries::normalize($term)) . ' %';
    }

    private static function escape(string $term): string
    {
        return str_replace(
            [self::ESCAPE_CHARACTER, '%', '_'],
            [
                self::ESCAPE_CHARACTER . self::ESCAPE_CHARACTER,
                self::ESCAPE_CHARACTER . '%',
                self::ESCAPE_CHARACTER . '_',
            ],
            $term,
        );
    }

    private function __construct()
    {
    }
}
