<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\SavedSearchEntryRepository;
use App\Repository\SavedSearchListQuery;

/**
 * The combined saved-search list (#769) over the membership table (#1116):
 * the page of every entry any of the caller's searches matches.
 */
final readonly class SavedSearchEntries
{
    public function __construct(private SavedSearchEntryRepository $entries)
    {
    }

    public function list(SavedSearchListQuery $query): SavedSearchEntriesResult
    {
        return new SavedSearchEntriesResult(rows: $this->entries->listMembers($query));
    }
}
