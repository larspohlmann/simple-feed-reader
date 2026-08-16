<?php

declare(strict_types=1);

namespace App\Service\Search\Index;

use App\Service\Search\Exception\SearchEngineUnavailableException;

/**
 * The write side of the index gateway. See SearchIndexReader for why this is
 * a second interface rather than one wider one.
 */
interface SearchIndexWriter
{
    /**
     * Applies the index's searchable/filterable/sortable attributes; creates
     * the index on first call.
     *
     * @throws SearchEngineUnavailableException
     */
    public function configure(): void;

    /**
     * @param list<IndexedEntry> $entries
     *
     * @throws SearchEngineUnavailableException
     */
    public function upsert(array $entries): void;

    /**
     * @param list<int> $entryIds
     *
     * @throws SearchEngineUnavailableException
     */
    public function forget(array $entryIds): void;

    /**
     * Removes every document; the index and its settings survive.
     *
     * @throws SearchEngineUnavailableException
     */
    public function clear(): void;
}
