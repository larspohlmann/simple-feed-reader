<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntryListRow;
use App\Repository\SavedSearchEntryRepository;
use App\Repository\SavedSearchListQuery;

/**
 * The combined saved-search list (#769) over the membership table (#1116):
 * the page of rows and, per row, the first of the caller's searches (in
 * sidebar order) it belongs to — the badge the card shows.
 */
final readonly class SavedSearchEntries
{
    public function __construct(private SavedSearchEntryRepository $entries)
    {
    }

    public function list(SavedSearchListQuery $query): SavedSearchEntriesResult
    {
        $rows = $this->entries->listMembers($query);
        $entryIds = array_map(static fn (EntryListRow $row): int => (int) $row->entry->getId(), $rows);

        return new SavedSearchEntriesResult(
            rows: $rows,
            savedSearchIds: $this->entries->firstMatchingSavedSearchIds($entryIds, $query->savedSearchIds),
        );
    }
}
