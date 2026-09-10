<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntryListRow;

/**
 * What one combined saved-search read answers with. `savedSearchIds` maps each
 * shown entry to the first saved search (in sidebar order) that matched it —
 * the sidebar badge (#584). `matchCount` is the read's own frontier before
 * hydration drops ghosts and the unread filter drops read rows, so
 * EntryPage::withMatchCount can tell a full page from a short one.
 * `continuationRow` is the last candidate the page must resume past, which a
 * post-filtered (unread) page separates from its last shown row.
 */
final readonly class SavedSearchEntriesResult
{
    /**
     * @param list<EntryListRow> $rows
     * @param array<int, int>    $savedSearchIds entryId => first matching saved search id
     */
    public function __construct(
        public array $rows,
        public array $savedSearchIds,
        public int $matchCount,
        public ?EntryListRow $continuationRow = null,
    ) {
    }
}
