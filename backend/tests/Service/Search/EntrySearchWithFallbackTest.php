<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\EntrySearchQuery;
use App\Repository\FeedRepository;
use App\Service\Search\EntrySearchWithFallback;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\SearchIndexReader;
use App\Service\Search\IndexedEntrySearch;
use App\Service\Search\LikeEntrySearch;
use App\Service\Search\SearchEngineCapability;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * EntrySearchWithFallback is what services.yaml actually hands out for
 * EntrySearchInterface — the piece that makes the engine optional. Both
 * collaborators are real here: LikeEntrySearch's own query behaviour is
 * LikeEntrySearchTest's job and IndexedEntrySearch's is
 * IndexedEntrySearchTest's, so this test only has to prove which of the two
 * answers a given search, and what (if anything) gets logged while deciding.
 * The engine side is driven through FakeSearchIndexReader (built with a
 * $failure to drive the broken-engine paths), never a running Meilisearch.
 */
final class EntrySearchWithFallbackTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $feed = new Feed('https://example.com/feed.xml');
        $feed->setTitle('Example');
        $this->em->persist($feed);

        // A subscription so IndexedEntrySearch does not short-circuit before
        // ever asking the reader — see IndexedEntrySearchTest's own "no
        // subscriptions" case for that other behaviour.
        $this->em->persist(
            new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );

        $this->em->flush();
    }

    private function fallback(
        SearchIndexReader $reader,
        LoggerInterface $logger,
        string $engineUrl,
    ): EntrySearchWithFallback {
        $entryRepository = $this->em->getRepository(Entry::class);
        self::assertInstanceOf(EntryRepository::class, $entryRepository);

        /** @var FeedRepository $feedRepository */
        $feedRepository = self::getContainer()->get(FeedRepository::class);

        return new EntrySearchWithFallback(
            new IndexedEntrySearch($reader, $feedRepository, $entryRepository),
            new LikeEntrySearch($entryRepository),
            $logger,
            new SearchEngineCapability($engineUrl, ''),
        );
    }

    private function query(): EntrySearchQuery
    {
        return new EntrySearchQuery(
            userId: $this->user->getId() ?? 0,
            terms: SearchTerms::fromInput('angular'),
        );
    }

    public function testAnUnconfiguredEngineIsNeverCalledAndTheDatabaseAnswers(): void
    {
        $reader = new FakeSearchIndexReader(matchedWords: ['would-only-appear-if-called']);

        $result = $this->fallback($reader, new NullLogger(), '')->search($this->query());

        self::assertNull($reader->received);
        // LikeEntrySearch::search always returns rowsOnly(), whose
        // matchedWords is empty — the fake's non-empty list proves this came
        // from the database, not from an engine that was never called.
        self::assertSame([], $result->matchedWords);
    }

    public function testTheUnconfiguredPathLogsNothing(): void
    {
        $reader = new FakeSearchIndexReader();
        $logSpy = new TestHandler();

        $this->fallback($reader, new Logger('test', [$logSpy]), '')->search($this->query());

        self::assertSame([], $logSpy->getRecords());
    }

    public function testAConfiguredEngineAnswers(): void
    {
        $reader = new FakeSearchIndexReader(matchedWords: ['engine-word']);
        $logSpy = new TestHandler();

        $result = $this->fallback($reader, new Logger('test', [$logSpy]), 'http://meilisearch.test')
            ->search($this->query());

        self::assertNotNull($reader->received);
        self::assertSame(['engine-word'], $result->matchedWords);
        self::assertSame([], $logSpy->getRecords());
    }

    public function testAnUnavailableEngineFallsBackToTheDatabaseAndLogsExactlyOneWarning(): void
    {
        $reader = new FakeSearchIndexReader(
            failure: new SearchEngineUnavailableException('The search engine did not answer.'),
        );
        $logSpy = new TestHandler();

        $result = $this->fallback($reader, new Logger('test', [$logSpy]), 'http://meilisearch.test')
            ->search($this->query());

        self::assertSame([], $result->matchedWords);
        self::assertTrue($logSpy->hasWarningRecords());
        self::assertCount(1, $logSpy->getRecords());
    }

    public function testAnUnexpectedExceptionIsNotSwallowed(): void
    {
        $reader = new FakeSearchIndexReader(failure: new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->fallback($reader, new NullLogger(), 'http://meilisearch.test')->search($this->query());
    }
}
