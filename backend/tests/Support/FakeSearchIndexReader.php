<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Search\Index\IndexMatches;
use App\Service\Search\Index\IndexSearch;
use App\Service\Search\Index\SearchIndexReader;

/**
 * Stands in for the engine gateway so IndexedEntrySearchTest can assert on
 * exactly what it was asked, without a running Meilisearch.
 */
final class FakeSearchIndexReader implements SearchIndexReader
{
    public ?IndexSearch $received = null;

    /**
     * @param list<int>    $entryIds
     * @param list<string> $matchedWords
     */
    public function __construct(
        private readonly array $entryIds = [],
        private readonly array $matchedWords = [],
    ) {
    }

    public function find(IndexSearch $search): IndexMatches
    {
        $this->received = $search;

        return new IndexMatches($this->entryIds, $this->matchedWords);
    }
}
