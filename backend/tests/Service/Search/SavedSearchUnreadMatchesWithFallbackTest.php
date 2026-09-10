<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\FeedRepository;
use App\Repository\SavedSearchEntryRepository;
use App\Service\Search\DatabaseSavedSearchUnreadMatches;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\SearchIndexReader;
use App\Service\Search\IndexedSavedSearchEntries;
use App\Service\Search\IndexedSavedSearchUnreadMatches;
use App\Service\Search\SavedSearchTerm;
use App\Service\Search\SavedSearchUnreadMatchesWithFallback;
use App\Service\Search\SearchEngineCapability;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * SavedSearchUnreadMatchesWithFallback is what services.yaml will hand out for
 * SavedSearchUnreadMatchSource — the piece that makes the engine optional for
 * the combined saved-search mark-read flow. Both collaborators are real here:
 * DatabaseSavedSearchUnreadMatches's own query behaviour is
 * DatabaseSavedSearchUnreadMatchesTest's job and
 * IndexedSavedSearchUnreadMatches's is IndexedSavedSearchUnreadMatchesTest's,
 * so this test only has to prove which of the two answers a given call, and
 * what (if anything) gets logged while deciding. The engine side is driven
 * through FakeMultiSearchReader, never a running Meilisearch.
 */
final class SavedSearchUnreadMatchesWithFallbackTest extends DbTestCase
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

        // A subscription so IndexedSavedSearchUnreadMatches does not
        // short-circuit before ever asking the reader.
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
    ): SavedSearchUnreadMatchesWithFallback {
        /** @var EntryListRepository $entryListRepository */
        $entryListRepository = self::getContainer()->get(EntryListRepository::class);

        /** @var FeedRepository $feedRepository */
        $feedRepository = self::getContainer()->get(FeedRepository::class);

        /** @var SavedSearchEntryRepository $savedSearchRepository */
        $savedSearchRepository = self::getContainer()->get(SavedSearchEntryRepository::class);

        $list = new IndexedSavedSearchEntries($reader, $feedRepository, $entryListRepository);

        return new SavedSearchUnreadMatchesWithFallback(
            new IndexedSavedSearchUnreadMatches($list),
            new DatabaseSavedSearchUnreadMatches($savedSearchRepository),
            $logger,
            new SearchEngineCapability($engineUrl, ''),
        );
    }

    /** @return list<SavedSearchTerm> */
    private function savedSearches(): array
    {
        return [new SavedSearchTerm($this->savedSearchId, SearchTerms::fromInput('angular'))];
    }

    private function until(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-20T00:00:00Z');
    }

    public function testAnUnconfiguredEngineIsNeverCalledAndTheDatabaseAnswers(): void
    {
        $reader = new FakeMultiSearchReader();
        $logSpy = new TestHandler();

        $ids = $this->fallback($reader, new Logger('test', [$logSpy]), '')
            ->unreadMatchIdsUpTo($this->user->getId() ?? 0, $this->savedSearches(), $this->until());

        self::assertSame([], $reader->receivedRounds);
        self::assertSame([$this->matchId], $ids);
        self::assertSame([], $logSpy->getRecords());
    }

    public function testAConfiguredEngineAnswers(): void
    {
        $reader = new FakeMultiSearchReader();
        $logSpy = new TestHandler();

        $this->fallback($reader, new Logger('test', [$logSpy]), 'http://meilisearch.test')
            ->unreadMatchIdsUpTo($this->user->getId() ?? 0, $this->savedSearches(), $this->until());

        self::assertNotSame([], $reader->receivedRounds);
        self::assertSame([], $logSpy->getRecords());
    }

    public function testAnUnavailableEngineFallsBackToTheDatabaseAndLogsExactlyOneWarning(): void
    {
        $reader = new FakeMultiSearchReader(failure: new SearchEngineUnavailableException('no answer'));
        $logSpy = new TestHandler();

        $ids = $this->fallback($reader, new Logger('test', [$logSpy]), 'http://meilisearch.test')
            ->unreadMatchIdsUpTo($this->user->getId() ?? 0, $this->savedSearches(), $this->until());

        self::assertSame([$this->matchId], $ids); // the DB path answered
        self::assertTrue($logSpy->hasWarningRecords());
        self::assertCount(1, $logSpy->getRecords());
    }

    public function testAnUnexpectedExceptionIsNotSwallowed(): void
    {
        $reader = new FakeMultiSearchReader(failure: new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);

        $this->fallback($reader, new NullLogger(), 'http://meilisearch.test')
            ->unreadMatchIdsUpTo($this->user->getId() ?? 0, $this->savedSearches(), $this->until());
    }
}
