<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Entity\Entry;
use App\Entity\LinkCard;
use App\Enum\FeedStatus;
use App\Repository\FeedRepository;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\LinkCard\PostLinkCards;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Refresh\Model\FeedOutcome;
use App\Service\Search\EntryIndexer;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\DuplicateKeyViolation;
use App\Tests\Support\EntryIngestors;
use App\Tests\Support\FeedSchedulers;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\PostLinkCardsWiring;
use App\Tests\Support\FlushFailingEntityManager;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\ReloadsEntities;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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

        $result = $this->persister($this->entityManager)->persist(
            $feed,
            FetchOutcomeModel::succeeded(
                FetchResponseModel::fetched($feed->getUrl(), false, $this->rss(), '"e1"', null),
            ),
            $this->clock->now(),
        );

        self::assertSame(FeedOutcome::Fetched, $result->outcome);
        self::assertSame(2, $result->entriesCreated);
        self::assertSame('"e1"', $feed->getEtag());
        self::assertSame([], $this->logger->records);
    }

    public function testAFetchedPostGetsACardForTheLinkItShares(): void
    {
        $feed = $this->feed('https://mastodon.example/@someone.rss');
        $client = new MockHttpClient(new MockResponse(
            '<html lang="en"><head><meta property="og:title" content="Shared story"></head></html>',
            ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']],
        ));
        $postLinkCards = PostLinkCardsWiring::build($this->entityManager, $client, new NullLogger());

        $this->persister($this->entityManager, $postLinkCards)->persist(
            $feed,
            FetchOutcomeModel::succeeded(
                FetchResponseModel::fetched($feed->getUrl(), false, $this->postRss(), null, null),
            ),
            $this->clock->now(),
        );

        $post = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid' => 'post-1']);
        self::assertInstanceOf(Entry::class, $post);
        $cards = $this->entityManager->getRepository(LinkCard::class)->findBy(['entry' => $post]);
        self::assertCount(1, $cards);
        self::assertSame('https://example.com/story', $cards[0]->getContent()->url);
        self::assertSame('Shared story', $cards[0]->getContent()->title);
    }

    public function testAThrottledFetchIsLoggedAtInfo(): void
    {
        $feed = $this->feed('https://one.example.com/feed');

        $result = $this->persister($this->entityManager)->persist(
            $feed,
            FetchOutcomeModel::failed(new FeedThrottledException('HTTP 429', 90)),
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

        $result = $this->persister($this->entityManager)->persist(
            $feed,
            FetchOutcomeModel::failed($gone),
            $this->clock->now(),
        );

        self::assertSame(FeedOutcome::Failed, $result->outcome);
        $this->assertLoggedOnce('warning', 'Feed gone: {url}', ['url' => $feed->getUrl(), 'exception' => $gone]);
        self::assertSame(FeedStatus::Gone, $this->reload($feed)->getStatus());
    }

    public function testAFailedFetchIsLoggedAsAWarning(): void
    {
        $feed = $this->feed('https://one.example.com/feed');
        $unreachable = new FeedUnreachableException('connection refused');

        $result = $this->persister($this->entityManager)
            ->persist($feed, FetchOutcomeModel::failed($unreachable), $this->clock->now());

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

        $result = $this->persister($this->entityManager)->persist(
            $feed,
            FetchOutcomeModel::succeeded(FetchResponseModel::notModified($feed->getUrl(), false, null, null)),
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
        $failingEntityManager = new FlushFailingEntityManager($this->entityManager, thrown: $duplicate);

        $result = $this->persister($failingEntityManager)->persist(
            $feed,
            FetchOutcomeModel::succeeded(FetchResponseModel::notModified($feed->getUrl(), false, null, null)),
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
        $failingEntityManager = new FlushFailingEntityManager($this->entityManager, thrown: $staleLock);

        $result = $this->persister($failingEntityManager)->persist(
            $feed,
            FetchOutcomeModel::succeeded(FetchResponseModel::notModified($feed->getUrl(), false, null, null)),
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

        $this->persister($this->entityManager)->persist(
            $feed,
            FetchOutcomeModel::succeeded(
                FetchResponseModel::notModified('https://new.example.com/feed', false, null, null),
            ),
            $this->clock->now(),
        );

        self::assertSame('https://old.example.com/feed', $feed->getUrl());
    }

    public function testAPermanentRedirectTargetOfExactlyTheColumnLengthIsAdopted(): void
    {
        $feed = $this->feed('https://old.example.com/feed');
        $target = 'https://new.example.com/' . str_repeat('p', 726);
        self::assertSame(750, mb_strlen($target));

        $this->persister($this->entityManager)->persist(
            $feed,
            FetchOutcomeModel::succeeded(FetchResponseModel::notModified($target, true, null, null)),
            $this->clock->now(),
        );

        self::assertSame($target, $feed->getUrl());
    }

    public function testAPermanentRedirectTargetIsMeasuredInCharactersNotBytes(): void
    {
        $feed = $this->feed('https://old.example.com/feed');
        $target = 'https://new.example.com/' . str_repeat('ü', 726);
        self::assertSame(750, mb_strlen($target));

        $this->persister($this->entityManager)->persist(
            $feed,
            FetchOutcomeModel::succeeded(FetchResponseModel::notModified($target, true, null, null)),
            $this->clock->now(),
        );

        self::assertSame($target, $feed->getUrl());
    }

    private function persister(
        EntityManagerInterface $entityManager,
        ?PostLinkCards $postLinkCards = null,
    ): FeedOutcomePersister {
        /** @var FeedRepository $feedRepository */
        $feedRepository = $this->entityManager->getRepository(Feed::class);
        $bodyParser = self::getContainer()->get(FeedBodyParser::class);
        self::assertInstanceOf(FeedBodyParser::class, $bodyParser);

        return new FeedOutcomePersister(
            $entityManager,
            $feedRepository,
            $bodyParser,
            EntryIngestors::build($this->entityManager, $this->clock),
            FeedSchedulers::build($this->clock),
            new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger()),
            PostEnrichers::idle($this->entityManager, $this->clock),
            $postLinkCards ?? PostLinkCardsWiring::offline($this->entityManager),
            $this->logger,
        );
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

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

    private function postRss(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>T</title>'
            . '<item><link>https://mastodon.example/@someone/1</link><guid>post-1</guid>'
            . '<description>&lt;p&gt;Worth a read: https://example.com/story&lt;/p&gt;</description></item>'
            . '</channel></rss>';
    }
}
