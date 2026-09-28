<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership\SavedSearchMatcher;

use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\Model\IndexMatchesModel;
use App\Service\Search\Index\Model\IndexSearchModel;
use App\Service\Search\Membership\SavedSearchMatcher\IndexedSavedSearchMatcher;
use App\Service\Search\Model\SavedSearchTermModel;
use App\Service\Search\Model\SearchTermsModel;
use App\Tests\Service\Search\FakeMultiSearchReader;
use PHPUnit\Framework\TestCase;

final class IndexedSavedSearchMatcherTest extends TestCase
{
    public function testAsksOneQueryPerSearchAmongExactlyTheCandidatesAndMapsAnswersBySearchId(): void
    {
        $engine = new FakeMultiSearchReader([[
            new IndexMatchesModel([12, 5], []),
            new IndexMatchesModel([], []),
        ]]);

        $matches = (new IndexedSavedSearchMatcher($engine))->matchingIds(
            [$this->search(7, 'climate'), $this->search(9, 'rocket')],
            [5, 9, 12],
        );

        self::assertSame([7 => [12, 5], 9 => []], $matches);
        self::assertCount(1, $engine->receivedRounds);
        $round = $engine->receivedRounds[0];
        self::assertCount(2, $round);
        self::assertInstanceOf(IndexSearchModel::class, $round[0]);
        self::assertSame([5, 9, 12], $round[0]->entryIds);
        self::assertNull($round[0]->feedIds);
        self::assertSame(3, $round[0]->limit);
        self::assertSame('rocket', $round[1]->terms->terms[0]);
    }

    public function testNoCandidatesNeverAsksTheEngine(): void
    {
        $engine = new FakeMultiSearchReader();

        $matches = (new IndexedSavedSearchMatcher($engine))->matchingIds([$this->search(7, 'climate')], []);

        self::assertSame([7 => []], $matches);
        self::assertSame([], $engine->receivedRounds);
    }

    public function testAnUnavailableEnginePropagates(): void
    {
        $engine = new FakeMultiSearchReader([], new SearchEngineUnavailableException('down'));

        $this->expectException(SearchEngineUnavailableException::class);

        (new IndexedSavedSearchMatcher($engine))->matchingIds([$this->search(7, 'climate')], [1]);
    }

    private function search(int $id, string $term): SavedSearchTermModel
    {
        return new SavedSearchTermModel($id, SearchTermsModel::fromInput($term));
    }
}
