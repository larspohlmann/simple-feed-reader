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
        $escaped = str_replace(
            [self::ESCAPE_CHARACTER, '%', '_'],
            [
                self::ESCAPE_CHARACTER . self::ESCAPE_CHARACTER,
                self::ESCAPE_CHARACTER . '%',
                self::ESCAPE_CHARACTER . '_',
            ],
            $term,
        );

        return '%' . $escaped . '%';
    }
}
