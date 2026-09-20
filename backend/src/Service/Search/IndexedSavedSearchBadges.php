<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Http\EntryCursor;
use App\Repository\FeedRepository;
use App\Repository\SavedSearchBadgeCandidateRepository;
use App\Service\Search\Index\IndexMatches;
use App\Service\Search\Index\IndexSearch;
use App\Service\Search\Index\SearchIndexReader;

/**
 * The engine-consistent badge set: every search keyset-paged to exhaustion, unioned
 * through one unread/collapse/subscribed DB pass, intersected back per search (no cap).
 * Depends on the RAW reader so a mid-walk failure reaches SavedSearchBadgesWithFallback.
 */
final readonly class IndexedSavedSearchBadges implements SavedSearchBadgeSource
{
    /** Meilisearch's default maxTotalHits — the largest page the engine answers. */
    private const int ENGINE_PAGE = 1000;

    public function __construct(
        private SearchIndexReader $index,
        private FeedRepository $feeds,
        private SavedSearchBadgeCandidateRepository $candidates,
    ) {
    }

    public function unreadMatchIdsBySavedSearch(int $userId, array $searches): array
    {
        if ($searches === []) {
            return [];
        }

        $feedIds = $this->feeds->idsSubscribedByUser($userId);
        if ($feedIds === []) {
            return $this->emptyResultFor($searches);
        }

        $candidatesBySearch = $this->enumerateCandidates($searches, $feedIds);
        $unreadIds = $this->candidates->unreadCollapsedSubscribedIds(
            $this->distinctIds($candidatesBySearch),
            $userId,
        );

        return $this->intersectWithUnread($candidatesBySearch, array_fill_keys($unreadIds, true));
    }

    /**
     * @param list<SavedSearchTerm> $searches
     * @param list<int>             $feedIds
     *
     * @return array<int, list<int>> savedSearchId => candidate ids, engine order
     */
    private function enumerateCandidates(array $searches, array $feedIds): array
    {
        $candidates = $this->emptyResultFor($searches);
        $cursorBySearchId = [];
        $active = $searches;

        while ($active !== []) {
            $results = $this->index->findMany($this->roundOf($active, $feedIds, $cursorBySearchId));
            foreach ($active as $position => $search) {
                array_push($candidates[$search->id], ...$results[$position]->entryIds);
            }

            $boundaries = $this->roundBoundaries($active, $results);
            $cursorBySearchId = $this->nextCursors($boundaries);
            $active = $this->stillActive($active, $boundaries);
        }

        return $candidates;
    }

    /**
     * @param list<SavedSearchTerm>        $active
     * @param list<int>                    $feedIds
     * @param array<int, EntryCursor|null> $cursorBySearchId
     *
     * @return list<IndexSearch>
     */
    private function roundOf(array $active, array $feedIds, array $cursorBySearchId): array
    {
        return array_map(
            static fn (SavedSearchTerm $search): IndexSearch => new IndexSearch(
                $search->terms,
                $feedIds,
                $cursorBySearchId[$search->id] ?? null,
                self::ENGINE_PAGE,
            ),
            $active,
        );
    }

    /**
     * A search's last id, for one that came back at a full page — it may have
     * more; a partial page needs no boundary because it is exhausted.
     *
     * @param list<SavedSearchTerm> $active
     * @param list<IndexMatches>    $results
     *
     * @return array<int, int> savedSearchId => last id of that round's page
     */
    private function roundBoundaries(array $active, array $results): array
    {
        $boundaries = [];
        foreach ($active as $position => $search) {
            $ids = $results[$position]->entryIds;
            if (\count($ids) < self::ENGINE_PAGE) {
                continue;
            }
            $boundaries[$search->id] = $ids[array_key_last($ids)];
        }

        return $boundaries;
    }

    /**
     * The engine returns ids only, so each boundary id's effectiveDate — the
     * keyset cursor's sort instant — is resolved from the database, once per
     * round for every search that needs one.
     *
     * @param array<int, int> $boundaries savedSearchId => last id
     *
     * @return array<int, EntryCursor>
     */
    private function nextCursors(array $boundaries): array
    {
        if ($boundaries === []) {
            return [];
        }

        $effectiveDates = $this->candidates->effectiveDatesByIds(array_values($boundaries));

        $cursors = [];
        foreach ($boundaries as $searchId => $lastId) {
            $cursors[$searchId] = new EntryCursor($effectiveDates[$lastId], $lastId);
        }

        return $cursors;
    }

    /**
     * @param list<SavedSearchTerm> $active
     * @param array<int, int>       $boundaries
     *
     * @return list<SavedSearchTerm>
     */
    private function stillActive(array $active, array $boundaries): array
    {
        return array_values(
            array_filter($active, static fn (SavedSearchTerm $s): bool => isset($boundaries[$s->id])),
        );
    }

    /**
     * Every requested search keyed by id with an empty list — the DB method's
     * array_fill_keys shape, kept as one definition so every early-return path
     * in this class agrees with it.
     *
     * @param list<SavedSearchTerm> $searches
     *
     * @return array<int, list<int>>
     */
    private function emptyResultFor(array $searches): array
    {
        return array_fill_keys(array_map(static fn (SavedSearchTerm $s): int => $s->id, $searches), []);
    }

    /**
     * @param array<int, list<int>> $candidatesBySearch
     *
     * @return list<int>
     */
    private function distinctIds(array $candidatesBySearch): array
    {
        return array_values(array_unique(array_merge(...array_values($candidatesBySearch))));
    }

    /**
     * Every requested search keeps its key (`[]` when it has no unread matches),
     * the exact shape SavedSearchEntryRepository::unreadMatchIdsBySavedSearch
     * answers, so SavedSearchBadgesWithFallback swaps the two paths transparently.
     *
     * @param array<int, list<int>> $candidatesBySearch
     * @param array<int, true>      $unreadSet
     *
     * @return array<int, list<int>>
     */
    private function intersectWithUnread(array $candidatesBySearch, array $unreadSet): array
    {
        $matches = [];
        foreach ($candidatesBySearch as $searchId => $candidateIds) {
            $matches[$searchId] = array_values(array_filter(
                $candidateIds,
                static fn (int $id): bool => isset($unreadSet[$id]),
            ));
        }

        return $matches;
    }
}
