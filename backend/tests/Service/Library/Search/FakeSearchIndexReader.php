<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\Index\Model\IndexMatchesModel;
use App\Service\Search\Index\Model\IndexSearchModel;
use App\Service\Search\Index\SearchIndexReader\SearchIndexReaderInterface;

/**
 * Answers find() with the matches it was built with, or throws $failure when given one, the same shape as
 * RecordingSearchIndexWriter.
 */
final class FakeSearchIndexReader implements SearchIndexReaderInterface
{
    public ?IndexSearchModel $received = null;

    /** @var list<IndexSearchModel>|null */
    public ?array $receivedMany = null;

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

    public function find(IndexSearchModel $search): IndexMatchesModel
    {
        $this->received = $search;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new IndexMatchesModel($this->entryIds, $this->matchedWords);
    }

    /**
     * @param list<IndexSearchModel> $searches
     *
     * @return list<IndexMatchesModel>
     */
    public function findMany(array $searches): array
    {
        $this->receivedMany = $searches;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return array_map(
            fn (): IndexMatchesModel => new IndexMatchesModel($this->entryIds, $this->matchedWords),
            $searches,
        );
    }
}
