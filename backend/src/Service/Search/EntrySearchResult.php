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
     * How many ids the underlying read matched, before IndexedEntrySearch's
     * hydration step may have dropped some of them again — see
     * EntryPage::withMatchCount(). Set from $rows by default, which is
     * correct for every search path except the indexed one, which passes its
     * own count explicitly because its row count and match count can differ.
     */
    public int $matchCount;

    /**
     * @param list<EntryListRow> $rows
     * @param list<string>       $matchedWords
     * @param int|null           $matchCount defaults to count($rows)
     */
    public function __construct(
        public array $rows,
        public array $matchedWords,
        ?int $matchCount = null,
    ) {
        $this->matchCount = $matchCount ?? \count($rows);
    }

    /** @param list<EntryListRow> $rows */
    public static function rowsOnly(array $rows): self
    {
        return new self($rows, []);
    }
}
