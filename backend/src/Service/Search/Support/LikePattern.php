<?php

declare(strict_types=1);

namespace App\Service\Search\Support;

use App\Doctrine\CaseFolding;
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

    /**
     * A stretch native LIKE finds, ignoring case, in every row the full match accepts on any engine, so it can reject
     * rows before a folded or normalized haystack is computed. Alphanumerics alone survive both CaseFolding and
     * WordBoundaries unchanged.
     */
    public static function anchor(string $term): ?string
    {
        if (!CaseFolding::isNeededFor($term) && !WordBoundaries::areIn($term)) {
            return $term;
        }

        preg_match_all('/[A-Za-z0-9]+/', $term, $stretches);
        $longest = '';
        foreach ($stretches[0] as $stretch) {
            if (\strlen($stretch) > \strlen($longest)) {
                $longest = $stretch;
            }
        }

        return $longest === '' ? null : $longest;
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
