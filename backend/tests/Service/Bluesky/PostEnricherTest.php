<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\PendingPostEnrichment;
use App\Repository\PendingPostEnrichmentRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\EntryEmbedWriter\EntryEmbedWriter;
use App\Service\Bluesky\EntryEmbedWriter\EntryEmbedWriterInterface;
use App\Service\Bluesky\PendingPostQueue;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Bluesky\PostEnricher;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\HostThrottle;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Tests\DbTestCase;
use App\Tests\Support\Bluesky;
use App\Tests\Support\FailingEmbedWriter;
use App\Tests\Support\FakeAppView;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\StubFeedFetcher;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class PostEnricherTest extends DbTestCase
{
    use ReloadsEntities;

    private const string TISCH = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t';
    private const string APPLES = 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/3mv3shqdfuc2e';
    private const string TEST_POST = 'at://did:plc:test/app.bsky.feed.post/';

    private MockClock $clock;
    private HostThrottle $throttle;
    private FakeAppView $appView;
    private RecordingLogger $logger;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-10 12:00:00', 'UTC');
        $this->throttle = new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock);
        $this->appView = new FakeAppView();
        $this->logger = new RecordingLogger();
        $this->feed = $this->feed('https://bsky.app/profile/motherjones.com/rss');
    }

    public function testQueuesANewPostFillsItAndDequeuesIt(): void
    {
        $this->appView->knowsFixture('external');
        $post = $this->entry($this->feed, self::TISCH, '<p>Read this.<br />' . Bluesky::CARD . '</p>');
        $article = $this->entry($this->feed, 'https://example.com/article', '<p>Article.</p>');
        $this->entityManager->flush();

        $filled = $this->enricher()->enrich($this->feed, [$post, $article]);

        self::assertSame([$post], $filled);
        self::assertSame([[self::TISCH]], $this->appView->requests);
        self::assertSame(0, $this->pendingCount());
        $stored = $this->reload($post);
        self::assertStringStartsWith('<p>Read this.</p><figure class="link-card">', (string) $stored->getContentHtml());
        self::assertSame('Read this.', $stored->getSummary());
    }

    public function testAPostTheAppViewOmitsIsDequeuedAndKeepsItsText(): void
    {
        $post = $this->entry($this->feed, self::TEST_POST . 'deleted', '<p>Deleted since.</p>');
        $this->entityManager->flush();

        self::assertSame([], $this->enricher()->enrich($this->feed, [$post]));

        self::assertSame('<p>Deleted since.</p>', $this->reload($post)->getContentHtml());
        self::assertSame(0, $this->pendingCount());
    }

    public function testThirtyQueuedPostsTakeTwoRequests(): void
    {
        $this->queuePosts(30);

        $this->enricher()->enrich($this->feed, []);

        self::assertSame([25, 5], array_map(count(...), $this->appView->requests));
        self::assertSame(0, $this->pendingCount());
    }

    public function testAPassTakesTheOldestHundredPosts(): void
    {
        $guids = $this->queuePosts(101);

        $this->enricher()->enrich($this->feed, []);

        self::assertSame([25, 25, 25, 25], array_map(count(...), $this->appView->requests));
        self::assertSame($guids[0], $this->appView->requests[0][0]);
        self::assertSame([$guids[100]], $this->pendingGuids());
    }

    /** @return iterable<string, array{FetchException}> */
    public static function unreachableAppViews(): iterable
    {
        yield 'no answer' => [new FeedUnreachableException('connection reset')];
        yield 'a server error' => [new FeedUnreachableException('HTTP 503', 503)];
        yield 'a redirect without a location' => [new FeedUnreachableException('no Location', 302)];
    }

    #[DataProvider('unreachableAppViews')]
    public function testAnUnreachableAppViewStopsThePassAndBacksOff(FetchException $failure): void
    {
        $this->appView->failsWith($failure);
        $this->queuePosts(30);

        self::assertSame([], $this->enricher()->enrich($this->feed, []));

        self::assertCount(1, $this->appView->requests);
        self::assertSame(30, $this->pendingCount());
        self::assertSame(300, $this->throttle->remainingSeconds(Bluesky::GET_POSTS));
        self::assertSame(['warning'], array_column($this->logger->records, 'level'));
        self::assertSame('Bluesky AppView gave no usable answer for {url}', $this->logger->records[0]['message']);
        self::assertSame($this->feed->getUrl(), $this->logger->records[0]['context']['url']);
    }

    public function testTheBackoffSkipsTheNextFeedsPass(): void
    {
        $this->appView->failsWith(new FeedUnreachableException('connection reset'));
        $this->queuePosts(1);
        $this->enricher()->enrich($this->feed, []);
        $this->appView->recovers();
        $otherFeed = $this->feed('https://bsky.app/profile/bsky.app/rss');
        $this->queuedPost($otherFeed, self::TEST_POST . 'other', '-1 minute');
        $this->entityManager->flush();

        $this->enricher()->enrich($otherFeed, []);

        self::assertCount(1, $this->appView->requests);
        self::assertSame(2, $this->pendingCount());
    }

    /** @return iterable<string, array{FetchException}> */
    public static function rejectedRequests(): iterable
    {
        yield 'a bad request' => [new FeedUnreachableException('HTTP 400', 400)];
        yield 'the last client error' => [new FeedUnreachableException('HTTP 499', 499)];
        yield 'gone' => [new FeedGoneException('HTTP 410 Gone')];
    }

    #[DataProvider('rejectedRequests')]
    public function testARejectedRequestKeepsItsRowsAndTheNextChunkIsStillAsked(FetchException $failure): void
    {
        $this->appView->failsWith($failure);
        $this->queuePosts(30);

        self::assertSame([], $this->enricher()->enrich($this->feed, []));

        self::assertCount(2, $this->appView->requests);
        self::assertSame(30, $this->pendingCount());
        self::assertSame(0, $this->throttle->remainingSeconds(Bluesky::GET_POSTS));
        self::assertSame(['warning', 'warning'], array_column($this->logger->records, 'level'));
    }

    public function testAnUnreadableAnswerKeepsItsRowsAndTheNextChunkIsStillAsked(): void
    {
        $this->appView->answersWithBody('<html>Bad gateway</html>');
        $this->queuePosts(30);

        $this->enricher()->enrich($this->feed, []);

        self::assertCount(2, $this->appView->requests);
        self::assertSame(30, $this->pendingCount());
        self::assertSame(0, $this->throttle->remainingSeconds(Bluesky::GET_POSTS));
        self::assertSame(['warning', 'warning'], array_column($this->logger->records, 'level'));
    }

    public function testARowQueuedMoreThanThreeDaysAgoIsDroppedUnasked(): void
    {
        $this->queuedPost($this->feed, self::TEST_POST . 'stale', '-3 days -1 second');
        $this->queuedPost($this->feed, self::TEST_POST . 'young', '-3 days +1 minute');
        $this->queuedPost($this->feed('https://bsky.app/profile/bsky.app/rss'), self::TEST_POST . 'other', '-4 days');
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed, []);

        self::assertSame([[self::TEST_POST . 'young']], $this->appView->requests);
        self::assertSame([self::TEST_POST . 'other'], $this->pendingGuids());
    }

    public function testAThrottledHostIsNotAsked(): void
    {
        $this->throttle->record(Bluesky::GET_POSTS, 120);
        $this->queuePosts(3);

        $this->enricher()->enrich($this->feed, []);

        self::assertSame([], $this->appView->requests);
        self::assertSame(3, $this->pendingCount());
    }

    public function testAThrottledAnswerStopsThePass(): void
    {
        $this->appView->failsWith(new FeedThrottledException('HTTP 429', 300));
        $this->queuePosts(30);

        $this->enricher()->enrich($this->feed, []);

        self::assertCount(1, $this->appView->requests);
        self::assertSame(30, $this->pendingCount());
        self::assertSame(300, $this->throttle->remainingSeconds(Bluesky::GET_POSTS));
    }

    public function testAnImageTheEntryAlreadyHasIsNotOverwritten(): void
    {
        $this->appView->knowsFixture('images');
        $post = $this->entry($this->feed, self::APPLES, '<p>Apples.</p>');
        $post->getImage()->storePending('https://example.com/own.jpg', 800, 600);
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed, [$post]);

        self::assertSame('https://example.com/own.jpg', $this->reload($post)->getImageUrl());
    }

    public function testAnUnexpectedFailureIsLoggedAndFillsNothing(): void
    {
        $post = $this->entry($this->feed, self::TISCH, '<p>Text.</p>');
        $this->entityManager->flush();
        $enricher = PostEnrichers::build(
            $this->entityManager,
            $this->clock,
            new AppViewClient(new StubFeedFetcher(), $this->throttle),
            $this->logger,
        );

        self::assertSame([], $enricher->enrich($this->feed, [$post]));

        self::assertSame(['error'], array_column($this->logger->records, 'level'));
        self::assertSame('Bluesky enrichment failed for {url}', $this->logger->records[0]['message']);
        self::assertSame(1, $this->pendingCount());
    }

    public function testAnEntryThatFailsToFillIsLoggedAndDequeuedWhileTheOthersFill(): void
    {
        $this->appView->knowsFixture('external');
        $this->appView->knowsFixture('images');
        $broken = $this->entry($this->feed, self::TISCH, '<p>Broken.</p>');
        $apples = $this->entry($this->feed, self::APPLES, '<p>Apples.</p>');
        $this->entityManager->flush();
        $enricher = $this->enricherWith(new FailingEmbedWriter(self::TISCH, $this->embedWriter()));

        $filled = $enricher->enrich($this->feed, [$broken, $apples]);

        self::assertSame([$apples], $filled);
        self::assertSame(0, $this->pendingCount());
        self::assertStringStartsWith(
            '<p>Apples.</p><figure class="post-images">',
            (string) $this->reload($apples)->getContentHtml(),
        );
        self::assertSame(['warning'], array_column($this->logger->records, 'level'));
        self::assertSame('Bluesky post {entry} could not be filled', $this->logger->records[0]['message']);
        self::assertSame($broken->requireId(), $this->logger->records[0]['context']['entry']);
        self::assertInstanceOf(\RuntimeException::class, $this->logger->records[0]['context']['exception']);
    }

    public function testAFailedFlushIsRethrownSoTheRefreshAbortsUnderThisFeed(): void
    {
        $post = $this->entry($this->feed, self::TISCH, '<p>Text.</p>');
        $this->entityManager->persist(new PendingPostEnrichment($post, $this->clock->now()));
        $this->entityManager->flush();

        try {
            $this->enricher()->enrich($this->feed, [$post]);
            self::fail('A failed flush must be rethrown.');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertFalse($this->entityManager->isOpen());
        self::assertSame([], $this->appView->requests);
        self::assertSame([], $this->logger->records);
    }

    public function testQueuedAtAndTheCutoffAreUtcWhateverZoneTheClockIsIn(): void
    {
        $this->clock = new MockClock('2026-10-10 12:00:00', 'Europe/Berlin');
        $this->appView->failsWith(new FeedUnreachableException('connection reset'));
        $utc = new \DateTimeZone('UTC');
        $this->storedPost(self::TEST_POST . 'stale', new \DateTimeImmutable('2026-10-07 09:59:59', $utc));
        $this->storedPost(self::TEST_POST . 'young', new \DateTimeImmutable('2026-10-07 10:00:01', $utc));
        $fresh = $this->entry($this->feed, self::TEST_POST . 'fresh', '<p>Text.</p>');
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed, [$fresh]);

        self::assertEqualsCanonicalizing(
            [self::TEST_POST . 'young', self::TEST_POST . 'fresh'],
            $this->pendingGuids(),
        );
        self::assertSame('2026-10-10 10:00:00', $this->storedQueuedAt(self::TEST_POST . 'fresh'));
    }

    private function enricher(): PostEnricher
    {
        return PostEnrichers::build(
            $this->entityManager,
            $this->clock,
            new AppViewClient($this->appView, $this->throttle),
            $this->logger,
        );
    }

    private function enricherWith(EntryEmbedWriterInterface $embedWriter): PostEnricher
    {
        /** @var PendingPostEnrichmentRepository $pendingPosts */
        $pendingPosts = $this->entityManager->getRepository(PendingPostEnrichment::class);
        $naiveUtcClock = new NaiveUtcClock($this->clock);

        return new PostEnricher(
            $this->entityManager,
            $pendingPosts,
            new PendingPostQueue($this->entityManager, $naiveUtcClock),
            new AppViewClient($this->appView, $this->throttle),
            $embedWriter,
            $naiveUtcClock,
            $this->logger,
        );
    }

    private function embedWriter(): EntryEmbedWriter
    {
        return new EntryEmbedWriter(
            new PostEmbedRenderer(),
            new EntrySanitizer(new TrailingBlankRemover()),
            new EntryImageWriter(),
        );
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function entry(Feed $feed, string $guid, string $contentHtml): Entry
    {
        $entry = new Entry($feed, $guid, null, 'Post', $this->clock->now(), $this->clock->now());
        $entry->setContentHtml($contentHtml);
        $entry->getImage()->storePending(null, null, null);
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function queuedPost(Feed $feed, string $guid, string $queuedBefore): void
    {
        $this->storedPost($guid, $this->clock->now()->modify($queuedBefore), $feed);
    }

    private function storedPost(string $guid, \DateTimeImmutable $queuedAt, ?Feed $feed = null): void
    {
        $entry = $this->entry($feed ?? $this->feed, $guid, '<p>Text.</p>');
        $this->entityManager->persist(new PendingPostEnrichment($entry, $queuedAt));
    }

    /** @return list<string> the guids, oldest queued first, one minute apart and all within the last two hours */
    private function queuePosts(int $count): array
    {
        $guids = [];
        for ($index = 0; $index < $count; $index++) {
            $guid = self::TEST_POST . sprintf('%03d', $index);
            $guids[] = $guid;
            $this->queuedPost($this->feed, $guid, sprintf('-120 minutes +%d minutes', $index));
        }
        $this->entityManager->flush();

        return $guids;
    }

    private function pendingCount(): int
    {
        return $this->entityManager->getRepository(PendingPostEnrichment::class)->count([]);
    }

    private function storedQueuedAt(string $guid): string
    {
        $queuedAt = $this->entityManager->getConnection()->fetchOne(
            'SELECT pending.queued_at FROM pending_post_enrichment pending'
            . ' JOIN entry ON entry.id = pending.entry_id WHERE entry.guid = ?',
            [$guid],
        );
        self::assertIsString($queuedAt);

        return $queuedAt;
    }

    /** @return list<string> */
    private function pendingGuids(): array
    {
        return array_map(
            static fn (PendingPostEnrichment $pending): string => $pending->getEntry()->getGuid(),
            $this->entityManager->getRepository(PendingPostEnrichment::class)->findAll(),
        );
    }
}
