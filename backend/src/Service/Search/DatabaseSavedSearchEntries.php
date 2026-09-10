<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntryListRow;
use App\Repository\SavedSearchEntryQuery;
use App\Repository\SavedSearchEntryRepository;

/**
 * The combined saved-search list through the database — the AND-of-LIKE query
 * the reader ships today, now behind the same interface as the engine path so
 * SavedSearchEntriesWithFallback can choose between them. It returns exactly
 * what the controller assembled before #973: the repository rows, the DB badge
 * attribution, and a match count equal to the row count (the LIKE list returns
 * the page it shows, so the last row is the resume point and no continuation
 * row is needed).
 */
final readonly class DatabaseSavedSearchEntries implements SavedSearchEntriesInterface
{
    public function __construct(private SavedSearchEntryRepository $entries)
    {
    }

    public function list(SavedSearchEntryQuery $query): SavedSearchEntriesResult
    {
        $rows = $this->entries->listForSavedSearches($query);
        $entryIds = array_map(static fn (EntryListRow $row): int => (int) $row->entry->getId(), $rows);

        return new SavedSearchEntriesResult(
            rows: $rows,
            savedSearchIds: $this->entries->matchedSavedSearchIds($entryIds, $query->savedSearches),
        );
    }
}
