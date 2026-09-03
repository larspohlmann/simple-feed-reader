<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * Builds the LIKE pattern for one search term.
 *
 * A term is user input, so the two LIKE metacharacters in it must match
 * themselves rather than act as wildcards: without this, a search for "100%"
 * matches every row that starts with "100". The escape character is "!" and not
 * the more usual backslash, because MySQL reads a backslash inside a pattern as
 * a string escape as well, and one character carrying two meanings is a trap
 * for the next person to touch this. Every query that uses a pattern from here
 * MUST declare ESCAPE_CHARACTER in its ESCAPE clause.
 */
final readonly class LikePattern
{
    public const string ESCAPE_CHARACTER = '!';

    public static function containing(string $term): string
    {
        return '%' . self::escape($term) . '%';
    }

    /**
     * Matches a term padded with a literal space on each side, for use against a
     * haystack CONCAT(' ', …, ' ') has padded the same way. Pairs with
     * NormalizeWordBoundariesFunction, which turns bordering punctuation into
     * spaces so the padding lines up on a real word boundary.
     *
     * The term is normalized with the SAME replacement before escaping, so both
     * sides speak one alphabet. Without that, punctuation-carrying terms could
     * never match: "E-Mail" was searched verbatim in a haystack whose hyphen had
     * already become a space, so whole-word found nothing where substring found
     * plenty. Normalizing first also disposes of "!", itself a boundary
     * character, so it's a space by the time escape() runs and can't arrive doubled.
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
}
