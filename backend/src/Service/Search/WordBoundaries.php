<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * The punctuation a whole-word match treats as a word boundary.
 *
 * Both sides must normalize identically: the haystack in SQL
 * (`NormalizeWordBoundariesFunction`'s REPLACE chain), the search term here in
 * PHP before it becomes a LIKE pattern. Normalizing only the haystack broke
 * whole-word search on any term with punctuation — "E-Mail" became
 * `%%20E-Mail%%20%` against a haystack whose hyphen was already a space, so
 * nothing matched. German prose hits this constantly: E-Mail, Corona-Krise,
 * US-Wahl, Baden-Württemberg.
 *
 * A deliberate subset, not completeness: sentence punctuation, brackets,
 * straight and typographic quotes, hyphen and dashes, the slash — what German
 * and English prose actually puts against a word. Anything else is not
 * treated as a boundary.
 */
final readonly class WordBoundaries
{
    /** @var list<string> */
    public const array CHARACTERS = [
        '.', ',', ';', ':', '!', '?',
        '(', ')', '[', ']', '{', '}',
        '"', "'", '„', '“', '”', '‚', '‘', '’', '«', '»',
        '-', '–', '—',
        '/',
    ];

    /**
     * Replaces every boundary character with a space, exactly as the SQL side
     * does — one character in, one space out, with NO collapsing of the runs
     * that leaves behind. The symmetry is the point: "E--Mail" normalizes to
     * "E  Mail" on both sides and still matches, which a tidier version that
     * collapsed whitespace on only one side would break.
     */
    public static function normalize(string $value): string
    {
        return str_replace(self::CHARACTERS, ' ', $value);
    }

    /**
     * Whether normalizing would change this term — i.e. whether it carries any
     * boundary punctuation at all. Callers use it to decide whether a raw
     * substring test is still a sound prefilter for a normalized match.
     */
    public static function areIn(string $term): bool
    {
        return self::normalize($term) !== $term;
    }
}
