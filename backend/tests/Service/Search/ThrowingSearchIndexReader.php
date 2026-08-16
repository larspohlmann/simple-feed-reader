<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\Index\IndexMatches;
use App\Service\Search\Index\IndexSearch;
use App\Service\Search\Index\SearchIndexReader;

/**
 * Stands in for a broken or unreachable engine: every find() throws the
 * exception it was built with, so EntrySearchWithFallbackTest can drive both
 * the recognised failure (SearchEngineUnavailableException) and an
 * unrelated one, without a running Meilisearch.
 */
final readonly class ThrowingSearchIndexReader implements SearchIndexReader
{
    public function __construct(private \Throwable $failure)
    {
    }

    public function find(IndexSearch $search): IndexMatches
    {
        throw $this->failure;
    }
}
