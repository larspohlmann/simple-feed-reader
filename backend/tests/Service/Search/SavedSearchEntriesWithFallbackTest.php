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
use App\Repository\SavedSearchEntryQuery;
use App\Repository\SavedSearchEntryRepository;
use App\Service\Search\DatabaseSavedSearchEntries;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\IndexMatches;
use App\Service\Search\Index\SearchIndexReader;
use App\Service\Search\IndexedSavedSearchEntries;
use App\Service\Search\SavedSearchEntriesWithFallback;
use App\Service\Search\SavedSearchTerm;
use App\Service\Search\SearchEngineCapability;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * SavedSearchEntriesWithFallback is what services.yaml actually hands out for
 * SavedSearchEntriesInterface — the piece that makes the engine optional for
 * the combined saved-search list. Both collaborators are real here:
 * DatabaseSavedSearchEntries's own query behaviour is
 * DatabaseSavedSearchEntriesTest's job and IndexedSavedSearchEntries's is
 * IndexedSavedSearchEntriesTest's, so this test only has to prove which of
 * the two answers a given list, and what (if anything) gets logged while
 * deciding. The engine side is driven through FakeMultiSearchReader, never a
 * running Meilisearch.
 */
final class SavedSearchEntriesWithFallbackTest extends DbTestCase
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

        // A subscription so IndexedSavedSearchEntries does not short-circuit
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
    ): SavedSearchEntriesWithFallback {
        $entryListRepository = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $entryListRepository);

        /** @var FeedRepository $feedRepository */
        $feedRepository = self::getContainer()->get(FeedRepository::class);

        /** @var SavedSearchEntryRepository $savedSearchRepository */
        $savedSearchRepository = self::getContainer()->get(SavedSearchEntryRepository::class);

        return new SavedSearchEntriesWithFallback(
            new IndexedSavedSearchEntries($reader, $feedRepository, $entryListRepository),
            new DatabaseSavedSearchEntries($savedSearchRepository),
            $logger,
            new SearchEngineCapability($engineUrl, ''),
        );
    }

    private function query(): SavedSearchEntryQuery
    {
        return new SavedSearchEntryQuery(
            userId: $this->user->getId() ?? 0,
            savedSearches: [new SavedSearchTerm($this->savedSearchId, SearchTerms::fromInput('angular'))],
        );
    }

    public function testAnUnconfiguredEngineIsNeverCalledAndTheDatabaseAnswers(): void
    {
        $reader = new FakeMultiSearchReader();

        $result = $this->fallback($reader, new NullLogger(), '')->list($this->query());

        self::assertSame([], $reader->receivedRounds);
        self::assertNotEmpty($result->rows); // the DB path found the seeded match
    }

    public function testTheUnconfiguredPathLogsNothing(): void
    {
        $logSpy = new TestHandler();

        $this->fallback(new FakeMultiSearchReader(), new Logger('test', [$logSpy]), '')->list($this->query());

        self::assertSame([], $logSpy->getRecords());
    }

    public function testAConfiguredEngineAnswers(): void
    {
        // The engine returns the seeded entry's id; a configured engine must be used.
        $reader = new FakeMultiSearchReader([[new IndexMatches([$this->matchId], [])]]);
        $logSpy = new TestHandler();

        $this->fallback($reader, new Logger('test', [$logSpy]), 'http://meilisearch.test')->list($this->query());

        self::assertNotSame([], $reader->receivedRounds);
        self::assertSame([], $logSpy->getRecords());
    }

    public function testAnUnavailableEngineFallsBackToTheDatabaseAndLogsExactlyOneWarning(): void
    {
        $reader = new FakeMultiSearchReader(failure: new SearchEngineUnavailableException('no answer'));
        $logSpy = new TestHandler();

        $result = $this->fallback($reader, new Logger('test', [$logSpy]), 'http://meilisearch.test')
            ->list($this->query());

        self::assertNotEmpty($result->rows); // the DB path answered
        self::assertTrue($logSpy->hasWarningRecords());
        self::assertCount(1, $logSpy->getRecords());
    }

    public function testAnUnexpectedExceptionIsNotSwallowed(): void
    {
        $reader = new FakeMultiSearchReader(failure: new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);

        $this->fallback($reader, new NullLogger(), 'http://meilisearch.test')->list($this->query());
    }
}
