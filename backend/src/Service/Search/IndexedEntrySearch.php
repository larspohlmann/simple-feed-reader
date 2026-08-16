<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntryRepository;
use App\Repository\EntrySearchQuery;
use App\Repository\FeedRepository;
use App\Service\Search\Index\IndexSearch;
use App\Service\Search\Index\SearchIndexReader;

/**
 * Matching through the search index: ask the engine for entry ids scoped to
 * the caller's own subscribed feeds, then hydrate those ids through the same
 * projection every other list uses. That hydration step is what makes
 * per-user read state and the subscription access check behave identically
 * to the rest of the app — the engine's own filter is never the last word on
 * what a caller may see, EntryRepository::rowsByIdsForUser is.
 *
 * FeedRepository::idsSubscribedByUser already answers "which feeds may this
 * user see" for AccountDeleter; reusing it here rather than adding an
 * equivalent query to SubscriptionRepository is what keeps that one
 * "subscribed feed ids" query in one place.
 *
 * Does not catch SearchEngineUnavailableException itself: a caller that wants
 * the LIKE fallback on that failure decorates this class rather than this
 * class swallowing it.
 */
final readonly class IndexedEntrySearch implements EntrySearchInterface
{
    public function __construct(
        private SearchIndexReader $index,
        private FeedRepository $feeds,
        private EntryRepository $entries,
    ) {
    }

    public function search(EntrySearchQuery $query): EntrySearchResult
    {
        $feedIds = $this->feeds->idsSubscribedByUser($query->userId);
        if ($feedIds === []) {
            return EntrySearchResult::rowsOnly([]);
        }

        $matches = $this->index->find(new IndexSearch(
            terms: $query->terms->terms,
            feedIds: $feedIds,
            cursor: $query->cursor,
            limit: $query->limit,
        ));

        return new EntrySearchResult(
            $this->entries->rowsByIdsForUser($matches->entryIds, $query->userId),
            $matches->matchedWords,
        );
    }
}
