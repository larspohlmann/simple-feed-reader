<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntrySearchQuery;
use App\Repository\FeedRepository;
use App\Service\Search\Index\IndexSearch;
use App\Service\Search\Index\SearchIndexReader;

/**
 * Matching through the search index: ask the engine for entry ids scoped to
 * the caller's own subscribed feeds, then hydrate those ids through the same
 * projection every other list uses. That hydration is what makes per-user
 * read state and the subscription access check behave identically to the
 * rest of the app — the engine's own filter is never the last word on what
 * a caller may see, EntryListRepository::rowsByIdsForUser is.
 *
 * The unread refinement rides on that same hydration: the engine holds no
 * per-user read state, so it still ranks and paginates every match, and the
 * hydrated projection — which already folds in the caller's read state — is
 * what drops the read rows. The page then resumes past the last candidate the
 * engine returned, not the last unread row shown, so a page that is entirely
 * read still advances the cursor instead of ending the list (continuationRow).
 *
 * Reuses FeedRepository::idsSubscribedByUser (already answering "which feeds
 * may this user see" for AccountDeleter) rather than adding an equivalent
 * query to SubscriptionRepository, keeping that query in one place.
 *
 * Does not catch SearchEngineUnavailableException itself: a caller wanting
 * the LIKE fallback on that failure decorates this class instead.
 */
final readonly class IndexedEntrySearch implements EntrySearchInterface
{
    public function __construct(
        private SearchIndexReader $index,
        private FeedRepository $feeds,
        private EntryListRepository $entries,
    ) {
    }

    public function search(EntrySearchQuery $query): EntrySearchResult
    {
        $feedIds = $this->feeds->idsSubscribedByUser($query->userId);
        if ($feedIds === []) {
            return EntrySearchResult::rowsOnly([]);
        }

        $matches = $this->index->find(new IndexSearch(
            terms: $query->terms,
            feedIds: $feedIds,
            cursor: $query->cursor,
            limit: $query->limit,
        ));

        $candidates = $this->entries->rowsByIdsForUser($matches->entryIds, $query->userId);

        return new EntrySearchResult(
            rows: $query->unread ? $this->unreadOnly($candidates) : $candidates,
            matchedWords: $matches->matchedWords,
            // The engine's own count, not count($rows): rowsByIdsForUser's
            // subscription join can silently drop an id the engine returned (a ghost
            // from a failed async index delete), and the unread filter below drops
            // the read ones — but a dropped row still means the engine may hold
            // more matches beyond this page.
            matchCount: \count($matches->entryIds),
            continuationRow: $candidates[array_key_last($candidates)] ?? null,
        );
    }

    /**
     * The unread rows of a hydrated page — read state is already folded into
     * EntryListRow::$isHidden by the projection. The read rows still counted
     * toward the engine's page, so the caller resumes past them (continuationRow).
     *
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
