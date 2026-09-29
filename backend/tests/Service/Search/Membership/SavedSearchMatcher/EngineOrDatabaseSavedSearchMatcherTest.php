<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership\SavedSearchMatcher;

use App\Repository\DatabaseSavedSearchMatcher;
use App\Repository\SearchTermsPredicateBuilder;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Membership\SavedSearchMatcher\EngineOrDatabaseSavedSearchMatcher;
use App\Service\Search\Membership\SavedSearchMatcher\IndexedSavedSearchMatcher;
use App\Service\Search\Model\SavedSearchTermModel;
use App\Service\Search\Model\SearchTermsModel;
use App\Service\Search\SearchEngineCapability;
use App\Tests\Service\Search\FakeMultiSearchReader;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class EngineOrDatabaseSavedSearchMatcherTest extends TestCase
{
    public function testAnUnconfiguredEngineMeansTheDatabaseMatcherAnswers(): void
    {
        $engine = new FakeMultiSearchReader([], new SearchEngineUnavailableException('never asked'));
        $em = $this->createStub(EntityManagerInterface::class);

        $matcher = new EngineOrDatabaseSavedSearchMatcher(
            new IndexedSavedSearchMatcher($engine),
            new DatabaseSavedSearchMatcher($em, new SearchTermsPredicateBuilder()),
            new SearchEngineCapability('', ''),
        );

        self::assertSame([1 => []], $matcher->matchingIds([$this->search(1)], []));
        self::assertSame([], $engine->receivedRounds);
    }

    public function testAConfiguredEngineAnswersAndItsFailurePropagatesWithoutFallback(): void
    {
        $engine = new FakeMultiSearchReader([], new SearchEngineUnavailableException('down'));
        $em = $this->createStub(EntityManagerInterface::class);

        $matcher = new EngineOrDatabaseSavedSearchMatcher(
            new IndexedSavedSearchMatcher($engine),
            new DatabaseSavedSearchMatcher($em, new SearchTermsPredicateBuilder()),
            new SearchEngineCapability('http://meilisearch:7700', 'key'),
        );

        $this->expectException(SearchEngineUnavailableException::class);

        $matcher->matchingIds([$this->search(1)], [10]);
    }

    private function search(int $id): SavedSearchTermModel
    {
        return new SavedSearchTermModel($id, SearchTermsModel::fromInput('climate'));
    }
}
