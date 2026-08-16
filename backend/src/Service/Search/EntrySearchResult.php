<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntryListRow;

/**
 * What a search implementation answers with: the rows themselves, plus the
 * terms it actually matched. LIKE cannot say more than the caller already
 * knew, but an engine that stems or expands terms can, and callers need one
 * shape regardless of which implementation is behind the interface.
 */
final readonly class EntrySearchResult
{
    /**
     * @param list<EntryListRow> $rows
     * @param list<string>       $matchedWords
     */
    public function __construct(
        public array $rows,
        public array $matchedWords,
    ) {
    }

    /** @param list<EntryListRow> $rows */
    public static function rowsOnly(array $rows): self
    {
        return new self($rows, []);
    }
}
