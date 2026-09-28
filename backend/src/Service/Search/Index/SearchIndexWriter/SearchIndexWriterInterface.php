<?php

declare(strict_types=1);

namespace App\Service\Search\Index\SearchIndexWriter;

use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\Model\IndexedEntryModel;

/**
 * The write side of the index gateway. See SearchIndexReaderInterface for why this is
 * a second interface rather than one wider one.
 */
interface SearchIndexWriterInterface
{
    /**
     * Applies the index's searchable/filterable/sortable attributes; creates
     * the index on first call.
     *
     * @throws SearchEngineUnavailableException
     */
    public function configure(): void;

    /**
     * @param list<IndexedEntryModel> $entries
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
