<?php

declare(strict_types=1);

namespace App\Service\Search\EntrySearch;

use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntrySearchQuery;
use App\Repository\FeedRepository;
use App\Service\Search\Index\Model\IndexSearchModel;
use App\Service\Search\Index\SearchIndexReader\SearchIndexReaderInterface;
use App\Service\Search\Model\EntrySearchResultModel;

/**
 * Asks the engine for ids within the caller's subscribed feeds, then hydrates them through
 * EntryListRepository::rowsByIdsForUser, the access check that has the last word. The unread refinement drops read
 * rows after hydration, and the page resumes past the last candidate, not the last row shown (continuationRow).
 */
final readonly class IndexedEntrySearch implements EntrySearchInterface
{
    public function __construct(
        private SearchIndexReaderInterface $index,
        private FeedRepository $feeds,
        private EntryListRepository $entries,
    ) {
    }

    public function search(EntrySearchQuery $query): EntrySearchResultModel
    {
        $feedIds = $this->feeds->idsSubscribedByUser($query->userId);
        if ($feedIds === []) {
            return EntrySearchResultModel::rowsOnly([]);
        }

        $matches = $this->index->find(new IndexSearchModel(
            terms: $query->terms,
            feedIds: $feedIds,
            cursor: $query->cursor,
            limit: $query->limit,
            order: $query->order,
        ));

        $candidates = $query->order->arrange(
            $this->entries->rowsByIdsForUser($query->userId, $matches->entryIds),
        );

        return new EntrySearchResultModel(
            rows: $query->unread ? $this->unreadOnly($candidates) : $candidates,
            matchedWords: $matches->matchedWords,
            // The engine's count, not count($rows): hydration drops ghost ids and the unread filter drops read rows,
            // yet the engine may still hold matches beyond this page.
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
