<?php

declare(strict_types=1);

namespace App\Service\Search\Index\SearchIndexReader;

use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\IndexMatches;
use App\Service\Search\Index\IndexSearch;

/**
 * The read side of the index gateway. Separate from SearchIndexWriterInterface so that
 * IndexedEntrySearch — the only caller that ever searches — cannot be handed a
 * dependency wide enough to also write, and app:search:reindex — the only
 * caller that ever writes — cannot be handed one wide enough to also search.
 */
interface SearchIndexReaderInterface
{
    /** @throws SearchEngineUnavailableException */
    public function find(IndexSearch $search): IndexMatches;

    /**
     * @param list<IndexSearch> $searches
     *
     * @return list<IndexMatches> result i pairs with searches[i], in request order
     *
     * @throws SearchEngineUnavailableException
     */
    public function findMany(array $searches): array;
}
