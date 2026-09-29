<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\Index\Model\IndexMatchesModel;
use App\Service\Search\Index\Model\IndexSearchModel;
use App\Service\Search\Index\SearchIndexReader\SearchIndexReaderInterface;

/**
 * Answers each findMany() with the next queued round and records every round, so a test can assert on the searches
 * and paging the combined saved-search path drove. find() is unused: that path only batches.
 */
final class FakeMultiSearchReader implements SearchIndexReaderInterface
{
    /** @var list<list<IndexSearchModel>> */
    public array $receivedRounds = [];

    /** @param list<list<IndexMatchesModel>> $rounds */
    public function __construct(
        private array $rounds = [],
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function find(IndexSearchModel $search): IndexMatchesModel
    {
        throw new \LogicException('FakeMultiSearchReader answers findMany only.');
    }

    public function findMany(array $searches): array
    {
        $this->receivedRounds[] = $searches;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return array_shift($this->rounds)
            ?? array_map(static fn (): IndexMatchesModel => new IndexMatchesModel([], []), $searches);
    }
}
