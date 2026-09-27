<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * The punctuation a whole-word match treats as a word boundary. The search term (here) and the haystack
 * (NormalizeWordBoundariesFunction's REPLACE chain) must normalize identically, or "E-Mail" stops matching itself.
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

    /** One space per boundary character and no collapsing of runs, exactly as the SQL side does. */
    public static function normalize(string $value): string
    {
        return str_replace(self::CHARACTERS, ' ', $value);
    }

    public static function areIn(string $term): bool
    {
        return self::normalize($term) !== $term;
    }
}
