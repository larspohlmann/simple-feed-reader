<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership;

use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\IndexMatches;
use App\Service\Search\Index\IndexSearch;
use App\Service\Search\Membership\IndexedSavedSearchMatcher;
use App\Service\Search\SavedSearchTerm;
use App\Service\Search\SearchTerms;
use App\Tests\Service\Search\FakeMultiSearchReader;
use PHPUnit\Framework\TestCase;

final class IndexedSavedSearchMatcherTest extends TestCase
{
    public function testAsksOneQueryPerSearchAmongExactlyTheCandidatesAndMapsAnswersBySearchId(): void
    {
        $engine = new FakeMultiSearchReader([[
            new IndexMatches([12, 5], []),
            new IndexMatches([], []),
        ]]);

        $matches = (new IndexedSavedSearchMatcher($engine))->matchingIds(
            [$this->search(7, 'climate'), $this->search(9, 'rocket')],
            [5, 9, 12],
        );

        self::assertSame([7 => [12, 5], 9 => []], $matches);
        self::assertCount(1, $engine->receivedRounds);
        $round = $engine->receivedRounds[0];
        self::assertCount(2, $round);
        self::assertInstanceOf(IndexSearch::class, $round[0]);
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

    private function search(int $id, string $term): SavedSearchTerm
    {
        return new SavedSearchTerm($id, SearchTerms::fromInput($term));
    }
}
