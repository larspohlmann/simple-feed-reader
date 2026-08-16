<?php

declare(strict_types=1);

namespace App\Service\Search\Index;

use App\Service\Search\Exception\SearchEngineUnavailableException;

/**
 * The read side of the index gateway. Separate from SearchIndexWriter so that
 * IndexedEntrySearch — the only caller that ever searches — cannot be handed a
 * dependency wide enough to also write, and app:search:reindex — the only
 * caller that ever writes — cannot be handed one wide enough to also search.
 */
interface SearchIndexReader
{
    /** @throws SearchEngineUnavailableException */
    public function find(IndexSearch $search): IndexMatches;
}
