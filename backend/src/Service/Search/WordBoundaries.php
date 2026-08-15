<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * The punctuation a whole-word match treats as a word boundary.
 *
 * Both sides of that match must agree character for character. The haystack is
 * normalized in SQL (`NormalizeWordBoundariesFunction` emits the REPLACE
 * chain); the search term is normalized here, in PHP, before it becomes a LIKE
 * pattern. When only the haystack was normalized, a whole-word search for any
 * term carrying punctuation could never match: "E-Mail" was looked for in a
 * haystack where the hyphen had already become a space, so `%%20E-Mail%%20%`
 * found nothing while the plain substring search found plenty. German prose
 * makes that the common case, not an exotic one — E-Mail, Corona-Krise,
 * US-Wahl, Baden-Württemberg.
 *
 * The list is a deliberate subset, not an attempt at completeness: sentence
 * punctuation, brackets, straight and typographic quotes, the hyphen and its
 * longer dashes, and the slash — what German and English prose actually puts
 * against a word. Anything left off simply fails to be a word boundary.
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
