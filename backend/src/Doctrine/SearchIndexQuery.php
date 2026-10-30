<?php

declare(strict_types=1);

namespace App\Doctrine;

/** The MATCH query for the SQLite search index (EntrySearchIndex), built from the pieces it can find. */
final readonly class SearchIndexQuery
{
    /** A trigram index finds nothing shorter, and one such piece ANDed in empties the whole MATCH. */
    private const int MIN_PIECE_LENGTH = 3;

    public static function isIndexable(string $piece): bool
    {
        return mb_strlen($piece) >= self::MIN_PIECE_LENGTH;
    }

    /** @param non-empty-list<string> $pieces each a literal substring, quotes included */
    public static function allOf(array $pieces): string
    {
        return implode(' AND ', array_map(
            static fn (string $piece): string => '"' . str_replace('"', '""', $piece) . '"',
            $pieces,
        ));
    }

    /**
     * The words a whole-word term leaves once its boundaries are spaces: "E-Mail" must find "E–Mail", which holds
     * "Mail" but not "E-Mail".
     *
     * @return list<string>
     */
    public static function wholeWordPieces(string $term): array
    {
        return array_values(array_filter(
            explode(' ', WordBoundaries::normalize($term)),
            self::isIndexable(...),
        ));
    }

    private function __construct()
    {
    }
}
