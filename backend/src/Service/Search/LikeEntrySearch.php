<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntryRepository;
use App\Repository\EntrySearchQuery;

/**
 * Matching by an AND of escaped LIKE predicates — the one implementation the
 * reader ships today. It behaves identically on SQLite and MySQL, which is why
 * the native test suite exercises the query that production runs.
 */
final readonly class LikeEntrySearch implements EntrySearchInterface
{
    public function __construct(private EntryRepository $entries)
    {
    }

    public function search(EntrySearchQuery $query): EntrySearchResult
    {
        return EntrySearchResult::rowsOnly($this->entries->searchForUser($query));
    }
}
