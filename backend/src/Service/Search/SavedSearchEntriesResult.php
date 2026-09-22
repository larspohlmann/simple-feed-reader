<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntryListRow;

/**
 * What one combined saved-search read answers with. `savedSearchIds` maps each
 * shown entry to the first saved search (in sidebar order) that matched it —
 * the sidebar badge (#584). `continuationRow` is the last candidate the page
 * must resume past, which a post-filtered (unread) page separates from its last
 * shown row.
 */
final readonly class SavedSearchEntriesResult
{
    /**
     * The read's own frontier before hydration; equals count($rows) now that
     * the list is the match set, kept so EntryPage::withMatchCount has one
     * shape.
     */
    public int $matchCount;

    /**
     * @param list<EntryListRow> $rows
     * @param array<int, int>    $savedSearchIds entryId => first matching saved search id
     */
    public function __construct(
        public array $rows,
        public array $savedSearchIds,
        ?int $matchCount = null,
        public ?EntryListRow $continuationRow = null,
    ) {
        $this->matchCount = $matchCount ?? \count($rows);
    }

    /** @param list<EntryListRow> $rows */
    public function withRows(array $rows): self
    {
        return new self($rows, $this->savedSearchIds, $this->matchCount, $this->continuationRow);
    }
}
