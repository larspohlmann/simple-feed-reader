<?php

declare(strict_types=1);

namespace App\Service\Search\Membership;

use App\Service\Search\Index\IndexSearch;
use App\Service\Search\Index\SearchIndexReader;
use App\Service\Search\SavedSearchTerm;

/**
 * The engine matcher: one multi-search per chunk, one query per search, each
 * filtered to exactly the candidate ids. Content and typo matches included —
 * the recall the engine host has always had.
 */
final readonly class IndexedSavedSearchMatcher implements SavedSearchMatcher
{
    public function __construct(private SearchIndexReader $index)
    {
    }

    public function matchingIds(array $searches, array $candidateEntryIds): array
    {
        if ($searches === [] || $candidateEntryIds === []) {
            return array_fill_keys(array_map(static fn (SavedSearchTerm $s): int => $s->id, $searches), []);
        }

        $results = $this->index->findMany(array_map(
            static fn (SavedSearchTerm $search): IndexSearch => IndexSearch::amongEntries(
                $search->terms,
                $candidateEntryIds,
                \count($candidateEntryIds),
            ),
            $searches,
        ));

        $matches = [];
        foreach ($searches as $position => $search) {
            $matches[$search->id] = $results[$position]->entryIds;
        }

        return $matches;
    }
}
