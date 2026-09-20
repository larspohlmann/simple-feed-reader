<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\DuplicateCollapseDql;
use App\Repository\EntryScopePredicates;
use App\Repository\FeedRepository;
use App\Repository\SavedSearchBadgeCandidateRepository;
use App\Repository\SavedSearchEntryRepository;
use App\Service\Search\DatabaseSavedSearchBadges;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\IndexMatches;
use App\Service\Search\Index\SearchIndexReader;
use App\Service\Search\IndexedSavedSearchBadges;
use App\Service\Search\SavedSearchBadgesWithFallback;
use App\Service\Search\SavedSearchTerm;
use App\Service\Search\SearchEngineCapability;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;
use Doctrine\Persistence\ManagerRegistry;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * SavedSearchBadgesWithFallback is what services.yaml hands out for
 * SavedSearchBadgeSource — the piece that makes the engine optional for the
 * sidebar badge. Both collaborators are real here: DatabaseSavedSearchBadges's
 * own query behaviour is DatabaseSavedSearchBadgesTest's job and
 * IndexedSavedSearchBadges's is IndexedSavedSearchBadgesTest's, so this test
 * only has to prove which of the two answers a given call, and what (if
 * anything) gets logged while deciding. The engine side is driven through
 * FakeMultiSearchReader, never a running Meilisearch.
 */
final class SavedSearchBadgesWithFallbackTest extends DbTestCase
{
    private User $user;

    private int $savedSearchId;

    private int $matchId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $feed = new Feed('https://example.com/feed.xml');
        $feed->setTitle('Example');
        $this->em->persist($feed);

        // A subscription so IndexedSavedSearchBadges does not short-circuit
        // before ever asking the reader.
        $this->em->persist(
            new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );

        $savedSearch = new SavedSearch($this->user, 'angular', false);
        $this->em->persist($savedSearch);

        $entry = new Entry(
            $feed,
            'a',
            'https://example.com/a',
            'Angular one',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->em->persist($entry);

        $this->em->flush();

        $this->savedSearchId = $savedSearch->getId() ?? 0;
        $this->matchId = $entry->getId() ?? 0;
    }

    private function fallback(
        SearchIndexReader $reader,
        LoggerInterface $logger,
        string $engineUrl,
    ): SavedSearchBadgesWithFallback {
        $container = self::getContainer();
        /** @var ManagerRegistry $registry */
        $registry = $container->get(ManagerRegistry::class);
        /** @var EntryScopePredicates $scope */
        $scope = $container->get(EntryScopePredicates::class);
        /** @var DuplicateCollapseDql $collapse */
        $collapse = $container->get(DuplicateCollapseDql::class);
        /** @var FeedRepository $feedRepository */
        $feedRepository = $container->get(FeedRepository::class);
        /** @var SavedSearchEntryRepository $savedSearchRepository */
        $savedSearchRepository = $container->get(SavedSearchEntryRepository::class);

        return new SavedSearchBadgesWithFallback(
            new IndexedSavedSearchBadges(
                $reader,
                $feedRepository,
                new SavedSearchBadgeCandidateRepository($registry, $scope, $collapse),
            ),
            new DatabaseSavedSearchBadges($savedSearchRepository),
            $logger,
            new SearchEngineCapability($engineUrl, ''),
        );
    }

    /** @return list<SavedSearchTerm> */
    private function savedSearches(): array
    {
        return [new SavedSearchTerm($this->savedSearchId, SearchTerms::fromInput('angular'))];
    }

    public function testAnUnconfiguredEngineIsNeverCalledAndTheDatabaseAnswers(): void
    {
        $reader = new FakeMultiSearchReader();
        $logSpy = new TestHandler();

        $idsBySearch = $this->fallback($reader, new Logger('test', [$logSpy]), '')
            ->unreadMatchIdsBySavedSearch($this->user->getId() ?? 0, $this->savedSearches());

        self::assertSame([], $reader->receivedRounds);
        self::assertSame([$this->savedSearchId => [$this->matchId]], $idsBySearch);
        self::assertSame([], $logSpy->getRecords());
    }

    public function testAConfiguredEngineAnswers(): void
    {
        $reader = new FakeMultiSearchReader([[new IndexMatches([$this->matchId], [])]]);
        $logSpy = new TestHandler();

        $idsBySearch = $this->fallback($reader, new Logger('test', [$logSpy]), 'http://meilisearch.test')
            ->unreadMatchIdsBySavedSearch($this->user->getId() ?? 0, $this->savedSearches());

        self::assertNotSame([], $reader->receivedRounds);
        self::assertSame([$this->savedSearchId => [$this->matchId]], $idsBySearch);
        self::assertSame([], $logSpy->getRecords());
    }

    public function testAnUnavailableEngineFallsBackToTheDatabaseAndLogsExactlyOneWarning(): void
    {
        $failure = new SearchEngineUnavailableException('no answer');
        $reader = new FakeMultiSearchReader(failure: $failure);
        $logSpy = new TestHandler();

        $idsBySearch = $this->fallback($reader, new Logger('test', [$logSpy]), 'http://meilisearch.test')
            ->unreadMatchIdsBySavedSearch($this->user->getId() ?? 0, $this->savedSearches());

        self::assertSame([$this->savedSearchId => [$this->matchId]], $idsBySearch); // the DB path answered
        self::assertTrue($logSpy->hasWarningRecords());
        $records = $logSpy->getRecords();
        self::assertCount(1, $records);
        self::assertSame($failure, $records[0]->context['exception']);
    }

    public function testAnUnexpectedExceptionIsNotSwallowed(): void
    {
        $reader = new FakeMultiSearchReader(failure: new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);

        $this->fallback($reader, new NullLogger(), 'http://meilisearch.test')
            ->unreadMatchIdsBySavedSearch($this->user->getId() ?? 0, $this->savedSearches());
    }
}
