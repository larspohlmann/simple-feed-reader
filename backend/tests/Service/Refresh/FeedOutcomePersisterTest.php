<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Enum\FeedStatus;
use App\Repository\FeedRepository;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FetchOutcome;
use App\Service\Fetch\FetchResponse;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\FeedOutcome;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Search\EntryIndexer;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\DuplicateKeyViolation;
use App\Tests\Support\EntryIngestors;
use App\Tests\Support\FeedSchedulers;
use App\Tests\Support\FlushFailingEntityManager;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\ReloadsEntities;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class FeedOutcomePersisterTest extends DbTestCase
{
    use ReloadsEntities;

    private MockClock $clock;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-07-21 12:00:00', 'UTC');
        $this->logger = new RecordingLogger();
    }

    public function testAFetchedDocumentReportsTheEntriesItCreatedAndLogsNothing(): void
    {
        $feed = $this->feed('https://one.example.com/feed');

        $result = $this->persister($this->em)->persist(
            $feed,
            FetchOutcome::succeeded(FetchResponse::fetched($feed->getUrl(), false, $this->rss(), '"e1"', null)),
            $this->clock->now(),
        );

        self::assertSame(FeedOutcome::Fetched, $result->outcome);
        self::assertSame(2, $result->entriesCreated);
        self::assertSame('"e1"', $feed->getEtag());
        self::assertSame([], $this->logger->records);
    }

    public function testAThrottledFetchIsLoggedAtInfo(): void
    {
        $feed = $this->feed('https://one.example.com/feed');

        $result = $this->persister($this->em)->persist(
            $feed,
            FetchOutcome::failed(new FeedThrottledException('HTTP 429', 90)),
            $this->clock->now(),
        );

        self::assertSame(FeedOutcome::Throttled, $result->outcome);
        self::assertSame(0, $result->entriesCreated);
        $this->assertLoggedOnce('info', 'Feed rate limited: {url}', ['url' => $feed->getUrl()]);
    }

    public function testAGoneFeedIsLoggedAsAWarning(): void
    {
        $feed = $this->feed('https://one.example.com/feed');
        $gone = new FeedGoneException('HTTP 410 Gone');

        $result = $this->persister($this->em)->persist($feed, FetchOutcome::failed($gone), $this->clock->now());

        self::assertSame(FeedOutcome::Failed, $result->outcome);
        $this->assertLoggedOnce('warning', 'Feed gone: {url}', ['url' => $feed->getUrl(), 'exception' => $gone]);
        self::assertSame(FeedStatus::Gone, $this->reload($feed)->getStatus());
    }

    public function testAFailedFetchIsLoggedAsAWarning(): void
    {
        $feed = $this->feed('https://one.example.com/feed');
        $unreachable = new FeedUnreachableException('connection refused');

        $result = $this->persister($this->em)->persist($feed, FetchOutcome::failed($unreachable), $this->clock->now());

        self::assertSame(FeedOutcome::Failed, $result->outcome);
        $this->assertLoggedOnce(
            'warning',
            'Feed refresh failed: {url}',
            ['url' => $feed->getUrl(), 'exception' => $unreachable],
        );
        self::assertSame('connection refused', $this->reload($feed)->getLastErrorMessage());
    }

    public function testANotModifiedFeedIsStoredAsASuccessThatBroughtNothingNew(): void
    {
        $feed = $this->feed('https://one.example.com/feed');

        $result = $this->persister($this->em)->persist(
            $feed,
            FetchOutcome::succeeded(FetchResponse::notModified($feed->getUrl(), false, null, null)),
            $this->clock->now(),
        );

        self::assertSame(FeedOutcome::NotModified, $result->outcome);
        $reloaded = $this->reload($feed);
        self::assertSame('2026-07-21 12:00:00', $reloaded->getLastSuccessfulFetchAt()?->format('Y-m-d H:i:s'));
        self::assertNull($reloaded->getLastNewEntryAt());
    }

    public function testAFailedFlushAbortsAndIsLoggedAsAnError(): void
    {
        $feed = $this->feed('https://one.example.com/feed');
        $duplicate = DuplicateKeyViolation::exception();
        $failingEm = new FlushFailingEntityManager($this->em, thrown: $duplicate);

        $result = $this->persister($failingEm)->persist(
            $feed,
            FetchOutcome::succeeded(FetchResponse::notModified($feed->getUrl(), false, null, null)),
            $this->clock->now(),
        );

        self::assertSame(FeedOutcome::Aborted, $result->outcome);
        $this->assertLoggedOnce(
            'error',
            'Refresh aborted: persistence failed for {url}',
            ['url' => $feed->getUrl(), 'exception' => $duplicate],
        );
    }

    public function testAFailedFlushInTheOrmAbortsToo(): void
    {
        $feed = $this->feed('https://one.example.com/feed');
        $staleLock = OptimisticLockException::lockFailed(Feed::class);
        $failingEm = new FlushFailingEntityManager($this->em, thrown: $staleLock);

        $result = $this->persister($failingEm)->persist(
            $feed,
            FetchOutcome::succeeded(FetchResponse::notModified($feed->getUrl(), false, null, null)),
            $this->clock->now(),
        );

        self::assertSame(FeedOutcome::Aborted, $result->outcome);
        $this->assertLoggedOnce(
            'error',
            'Refresh aborted: persistence failed for {url}',
            ['url' => $feed->getUrl(), 'exception' => $staleLock],
        );
    }

    public function testATemporaryRedirectLeavesTheFeedWhereItIs(): void
    {
        $feed = $this->feed('https://old.example.com/feed');

        $this->persister($this->em)->persist(
            $feed,
            FetchOutcome::succeeded(FetchResponse::notModified('https://new.example.com/feed', false, null, null)),
            $this->clock->now(),
        );

        self::assertSame('https://old.example.com/feed', $feed->getUrl());
    }

    public function testAPermanentRedirectTargetOfExactlyTheColumnLengthIsAdopted(): void
    {
        $feed = $this->feed('https://old.example.com/feed');
        $target = 'https://new.example.com/' . str_repeat('p', 726);
        self::assertSame(750, mb_strlen($target));

        $this->persister($this->em)->persist(
            $feed,
            FetchOutcome::succeeded(FetchResponse::notModified($target, true, null, null)),
            $this->clock->now(),
        );

        self::assertSame($target, $feed->getUrl());
    }

    public function testAPermanentRedirectTargetIsMeasuredInCharactersNotBytes(): void
    {
        $feed = $this->feed('https://old.example.com/feed');
        $target = 'https://new.example.com/' . str_repeat('ü', 726);
        self::assertSame(750, mb_strlen($target));

        $this->persister($this->em)->persist(
            $feed,
            FetchOutcome::succeeded(FetchResponse::notModified($target, true, null, null)),
            $this->clock->now(),
        );

        self::assertSame($target, $feed->getUrl());
    }

    private function persister(EntityManagerInterface $em): FeedOutcomePersister
    {
        /** @var FeedRepository $feedRepository */
        $feedRepository = $this->em->getRepository(Feed::class);
        $bodyParser = self::getContainer()->get(FeedBodyParser::class);
        self::assertInstanceOf(FeedBodyParser::class, $bodyParser);

        return new FeedOutcomePersister(
            $em,
            $feedRepository,
            $bodyParser,
            EntryIngestors::build($this->em, $this->clock),
            FeedSchedulers::build($this->clock),
            new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger()),
            $this->logger,
        );
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);
        $this->em->flush();

        return $feed;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function assertLoggedOnce(string $level, string $message, array $context): void
    {
        self::assertCount(1, $this->logger->records);
        self::assertSame($level, $this->logger->records[0]['level']);
        self::assertSame($message, $this->logger->records[0]['message']);
        self::assertSame($context, $this->logger->records[0]['context']);
    }

    private function rss(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>T</title>'
            . '<item><title>One</title><link>https://one.example.com/1</link><guid>one-1</guid></item>'
            . '<item><title>Two</title><link>https://one.example.com/2</link><guid>one-2</guid></item>'
            . '</channel></rss>';
    }
}
