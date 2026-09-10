<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\Index\IndexMatches;
use App\Service\Search\Index\IndexSearch;
use App\Service\Search\Index\SearchIndexReader;

/**
 * Drives the combined saved-search engine path without a running Meilisearch.
 * Each findMany() call answers the next queued round (a list of IndexMatches,
 * one per search in request order); it records every round it received so a
 * test can assert on the searches and the paging it drove. find() is unused
 * here — the combined path only ever batches.
 */
final class FakeMultiSearchReader implements SearchIndexReader
{
    /** @var list<list<IndexSearch>> */
    public array $receivedRounds = [];

    /** @param list<list<IndexMatches>> $rounds */
    public function __construct(
        private array $rounds = [],
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function find(IndexSearch $search): IndexMatches
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
            ?? array_map(static fn (): IndexMatches => new IndexMatches([], []), $searches);
    }
}
