<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\FeedRepository;
use App\Repository\SavedSearchEntryQuery;
use App\Service\Search\Index\IndexMatches;
use App\Service\Search\Index\IndexSearch;
use App\Service\Search\Index\SearchIndexReader;

/**
 * The combined saved-search list through the engine: one engine query per saved
 * search in a single /multi-search, unioned and hydrated once. Matching moves
 * to the engine (typo tolerance, full content, per-mode correctness); order and
 * access stay the database's — rowsByIdsForUser re-sorts newest-first and
 * applies the subscription gate, so the engine's own order and filter are never
 * the last word on what a caller sees (mirrors IndexedEntrySearch).
 *
 * Does not catch SearchEngineUnavailableException itself: a caller wanting the
 * LIKE fallback on that failure decorates this class instead.
 */
final readonly class IndexedSavedSearchEntries implements SavedSearchEntriesInterface
{
    public function __construct(
        private SearchIndexReader $index,
        private FeedRepository $feeds,
        private EntryListRepository $entries,
    ) {
    }

    public function list(SavedSearchEntryQuery $query): SavedSearchEntriesResult
    {
        if ($query->savedSearches === []) {
            return new SavedSearchEntriesResult([], []);
        }

        $feedIds = $this->feeds->idsSubscribedByUser($query->userId);
        if ($feedIds === []) {
            return new SavedSearchEntriesResult([], []);
        }

        $firstMatch = $this->firstMatchBySearch(
            $query->savedSearches,
            $this->index->findMany($this->indexSearches($query, $feedIds)),
        );

        // The engine can return up to (searches × limit) ids, but only the
        // newest $limit are shown; rowsByIdsForUser caps the hydration in SQL so
        // the discarded tail is never fetched. It already orders newest-first.
        $page = $this->entries->rowsByIdsForUser(array_keys($firstMatch), $query->userId, $query->limit);
        $rows = $query->onlyUnread ? $this->unreadOnly($page) : $page;

        return new SavedSearchEntriesResult(
            rows: $rows,
            savedSearchIds: $this->badgesFor($rows, $firstMatch),
            matchCount: \count($firstMatch),
            continuationRow: $page[array_key_last($page)] ?? null,
        );
    }

    /**
     * @param list<int> $feedIds
     *
     * @return list<IndexSearch>
     */
    private function indexSearches(SavedSearchEntryQuery $query, array $feedIds): array
    {
        return array_map(
            static fn (SavedSearchTerm $savedSearch): IndexSearch => new IndexSearch(
                terms: $savedSearch->terms,
                feedIds: $feedIds,
                cursor: $query->cursor,
                limit: $query->limit,
            ),
            $query->savedSearches,
        );
    }

    /**
     * entryId => the id of the first saved search (in sidebar order) whose
     * result held it. For a shown entry every matching search returned it, so
     * the first to return it is the first that matches — the same rule the DB
     * firstMatchExpression computes, kept consistent with the engine's matching.
     *
     * @param list<SavedSearchTerm> $savedSearches
     * @param list<IndexMatches>    $matches
     *
     * @return array<int, int>
     */
    private function firstMatchBySearch(array $savedSearches, array $matches): array
    {
        $firstMatch = [];
        foreach ($matches as $position => $result) {
            foreach ($result->entryIds as $entryId) {
                $firstMatch[$entryId] ??= $savedSearches[$position]->id;
            }
        }

        return $firstMatch;
    }

    /**
     * @param list<EntryListRow> $rows
     * @param array<int, int>    $firstMatch
     *
     * @return array<int, int>
     */
    private function badgesFor(array $rows, array $firstMatch): array
    {
        $badges = [];
        foreach ($rows as $row) {
            $entryId = (int) $row->entry->getId();
            $badges[$entryId] = $firstMatch[$entryId];
        }

        return $badges;
    }

    /**
     * @param list<EntryListRow> $candidates
     *
     * @return list<EntryListRow>
     */
    private function unreadOnly(array $candidates): array
    {
        return array_values(array_filter(
            $candidates,
            static fn (EntryListRow $row): bool => !$row->isHidden,
        ));
    }
}
