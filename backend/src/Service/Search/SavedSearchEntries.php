<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\SavedSearchEntryRepository;
use App\Repository\SavedSearchListQuery;
use App\Service\Search\Model\SavedSearchEntriesResultModel;

/**
 * The combined saved-search list over the membership table: the page of
 * every entry any of the caller's searches matches.
 */
final readonly class SavedSearchEntries
{
    public function __construct(private SavedSearchEntryRepository $entries)
    {
    }

    public function list(SavedSearchListQuery $query): SavedSearchEntriesResultModel
    {
        return new SavedSearchEntriesResultModel(rows: $this->entries->listMembers($query));
    }
}
