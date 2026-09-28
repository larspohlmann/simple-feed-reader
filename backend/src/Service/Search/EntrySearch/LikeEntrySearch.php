<?php

declare(strict_types=1);

namespace App\Service\Search\EntrySearch;

use App\Repository\EntryListRepository;
use App\Repository\EntrySearchQuery;
use App\Service\Search\Model\EntrySearchResultModel;

/**
 * Matching by an AND of escaped LIKE predicates — the one implementation the
 * reader ships today. It behaves identically on SQLite and MySQL, which is why
 * the native test suite exercises the query that production runs.
 */
final readonly class LikeEntrySearch implements EntrySearchInterface
{
    public function __construct(private EntryListRepository $entries)
    {
    }

    public function search(EntrySearchQuery $query): EntrySearchResultModel
    {
        return EntrySearchResultModel::rowsOnly($this->entries->searchForUser($query));
    }
}
