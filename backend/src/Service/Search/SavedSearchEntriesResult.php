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
     * The read's own frontier before hydration drops ghost ids and the unread
     * filter drops read rows, so EntryPage::withMatchCount can tell a full page
     * from a short one. Defaults to count($rows) — correct for the database
     * path, where the rows are the match set; the indexed path passes its union
     * size explicitly, since its row count and match count can differ.
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
}
