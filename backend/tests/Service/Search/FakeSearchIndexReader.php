<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\Index\IndexMatches;
use App\Service\Search\Index\IndexSearch;
use App\Service\Search\Index\SearchIndexReader;

/**
 * Stands in for the engine gateway. By default find() answers with the
 * matches it was built with, so IndexedEntrySearchTest can assert on exactly
 * what it was asked without a running Meilisearch. An optional $failure makes
 * it throw instead, driving EntrySearchWithFallbackTest's broken-engine
 * paths — the same shape RecordingSearchIndexWriter already uses for its own
 * failure case, kept identical because the read and write sides of the
 * gateway are deliberately symmetric.
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
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function find(IndexSearch $search): IndexMatches
    {
        $this->received = $search;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new IndexMatches($this->entryIds, $this->matchedWords);
    }
}
