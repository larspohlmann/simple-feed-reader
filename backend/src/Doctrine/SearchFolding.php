<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * The search's folding for a term and, on SQLite, for the haystack a LIKE still reads: accents off, then lower case,
 * as the entry_search index (EntrySearchIndex) folds and MySQL's utf8mb4_0900_ai_ci compares.
 */
final readonly class SearchFolding
{
    public static function fold(string $value): string
    {
        $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);
        $unaccented = preg_replace('/\p{Mn}+/u', '', \is_string($decomposed) ? $decomposed : $value);

        return mb_strtolower($unaccented ?? $value);
    }
}
