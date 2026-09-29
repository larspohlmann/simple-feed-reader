<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\EntrySearch;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntrySearchQuery;
use App\Repository\FeedRepository;
use App\Service\Search\EntrySearch\EntrySearchWithFallback;
use App\Service\Search\EntrySearch\IndexedEntrySearch;
use App\Service\Search\EntrySearch\LikeEntrySearch;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\SearchIndexReader\SearchIndexReaderInterface;
use App\Service\Search\Model\SearchTermsModel;
use App\Service\Search\SearchEngineCapability;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\FakeSearchIndexReader;
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
        $this->entityManager->persist($this->user);

        $feed = new Feed('https://example.com/feed.xml');
        $feed->setTitle('Example');
        $this->entityManager->persist($feed);

        // A subscription so IndexedEntrySearch does not short-circuit before
        // ever asking the reader — see IndexedEntrySearchTest's own "no
        // subscriptions" case for that other behaviour.
        $this->entityManager->persist(
            new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );

        $this->entityManager->flush();
    }

    private function fallback(
        SearchIndexReaderInterface $reader,
        LoggerInterface $logger,
        string $engineUrl,
    ): EntrySearchWithFallback {
        $entryListRepository = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $entryListRepository);

        /** @var FeedRepository $feedRepository */
        $feedRepository = self::getContainer()->get(FeedRepository::class);

        return new EntrySearchWithFallback(
            new IndexedEntrySearch($reader, $feedRepository, $entryListRepository),
            new LikeEntrySearch($entryListRepository),
            $logger,
            new SearchEngineCapability($engineUrl, ''),
        );
    }

    private function query(): EntrySearchQuery
    {
        return new EntrySearchQuery(
            userId: $this->user->requireId(),
            terms: SearchTermsModel::fromInput('angular'),
        );
    }

    private function unreadQuery(): EntrySearchQuery
    {
        return new EntrySearchQuery(
            userId: $this->user->requireId(),
            terms: SearchTermsModel::fromInput('angular'),
            unread: true,
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
        $failure = new SearchEngineUnavailableException('The search engine did not answer.');
        $reader = new FakeSearchIndexReader(failure: $failure);
        $logSpy = new TestHandler();

        $result = $this->fallback($reader, new Logger('test', [$logSpy]), 'http://meilisearch.test')
            ->search($this->query());

        self::assertSame([], $result->matchedWords);
        self::assertTrue($logSpy->hasWarningRecords());
        $records = $logSpy->getRecords();
        self::assertCount(1, $records);
        self::assertSame($failure, $records[0]->context['exception']);
    }

    public function testAnUnreadSearchStillRanksThroughTheConfiguredEngine(): void
    {
        // Read state lives in the database, not the index, but the ENGINE still
        // ranks the matches — IndexedEntrySearch drops the read rows after
        // hydration. So an unread search must not bypass a configured engine
        // and fall back to the LIKE query, which would lose typo tolerance and
        // relevance ranking exactly when the reader is refining a real search.
        $reader = new FakeSearchIndexReader(matchedWords: ['engine-word']);
        $logSpy = new TestHandler();

        $result = $this->fallback($reader, new Logger('test', [$logSpy]), 'http://meilisearch.test')
            ->search($this->unreadQuery());

        self::assertNotNull($reader->received);
        self::assertSame(['engine-word'], $result->matchedWords);
        self::assertSame([], $logSpy->getRecords());
    }

    public function testAnUnexpectedExceptionIsNotSwallowed(): void
    {
        $reader = new FakeSearchIndexReader(failure: new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->fallback($reader, new NullLogger(), 'http://meilisearch.test')->search($this->query());
    }
}
