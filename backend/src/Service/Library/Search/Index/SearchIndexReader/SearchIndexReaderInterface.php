<?php

declare(strict_types=1);

namespace App\Service\Library\Search\Index\SearchIndexReader;

use App\Service\Library\Search\Exception\SearchEngineUnavailableException;
use App\Service\Library\Search\Index\Model\IndexMatchesModel;
use App\Service\Library\Search\Index\Model\IndexSearchModel;

/** The read side of the index gateway, apart from SearchIndexWriterInterface so a searcher is never handed a writer. */
interface SearchIndexReaderInterface
{
    /** @throws SearchEngineUnavailableException */
    public function find(IndexSearchModel $search): IndexMatchesModel;

    /**
     * @param list<IndexSearchModel> $searches
     *
     * @return list<IndexMatchesModel> result i pairs with searches[i], in request order
     *
     * @throws SearchEngineUnavailableException
     */
    public function findMany(array $searches): array;
}
