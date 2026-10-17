<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Enum\ShareKind;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\EntryEmbedWriter\EntryEmbedWriter;
use App\Service\Bluesky\EntryEmbedWriter\EntryEmbedWriterInterface;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Bluesky\PostEnricher;
use App\Service\LinkCard\Model\LinkCardBudgetModel;
use App\Service\LinkCard\ShareLinkCards;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\CommentEmbedsModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\HostThrottle;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Service\Search\EntryIndexer;
use App\Service\Social\Pass\ReplyNode;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\Bluesky;
use App\Tests\Support\FailingEmbedWriter;
use App\Tests\Support\FakeAppView;
use App\Tests\Support\FakeReplyThreadSource;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\ShareLinkCardsWiring;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\SelfThreadEnrichers;
use App\Tests\Support\StubFeedFetcher;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PostEnricherTest extends DbTestCase
{
    use ReloadsEntities;

    private const string TISCH = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t';
    private const string TECHPRESSO = 'at://did:plc:t3o4nfbk4lq62necw2n35m4f/app.bsky.feed.post/3my2nlat6px2v';
    private const string APPLES = 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/3mv3shqdfuc2e';
    private const string TEST_POST = 'at://did:plc:test/app.bsky.feed.post/';
    private const string UNTITLED = self::TEST_POST . 'untitled';
    private const string QUOTING = 'at://did:plc:mtr3pwmbnirvyp3robuicd57/app.bsky.feed.post/3mxhy7vmqec2f';

    private MockClock $clock;
    private HostThrottle $throttle;
    private FakeAppView $appView;
    private RecordingLogger $logger;
    private RecordingSearchIndexWriter $indexWriter;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-10 12:00:00', 'UTC');
        $this->throttle = new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock);
        $this->appView = new FakeAppView();
        $this->logger = new RecordingLogger();
        $this->indexWriter = new RecordingSearchIndexWriter();
        $this->feed = $this->feed('https://bsky.app/profile/motherjones.com/rss');
    }

    public function testFillsANewPostAndDequeuesIt(): void
    {
        $this->appView->knowsFixture('external');
        $post = $this->queuedEntry(self::TISCH, '<p>Read this.<br />' . Bluesky::CARD . '</p>');
        $this->entry($this->feed, 'https://example.com/article', '<p>Article.</p>');
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed);

        self::assertSame([[self::TISCH]], $this->appView->requests);
        self::assertSame([$post->requireId()], $this->indexedIds());
        self::assertSame(0, $this->pendingCount());
        $stored = $this->reload($post);
        self::assertSame('<p>Read this.</p>', $stored->getContentHtml());
        self::assertCount(1, $this->cardsOf($stored));
        self::assertSame('Read this.', $stored->getSummary());
    }

    public function testAPostWithRepliesWaitsForItsThread(): void
    {
        $this->appView->knowsFixture('self-thread');
        $post = $this->queuedEntry(self::TECHPRESSO, '<p>Apple has updated.</p>');
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed);

        $rows = $this->rowsOf([$post]);
        self::assertCount(1, $rows);
        self::assertSame(ShareEnrichmentKind::SelfThread, $rows[0]->getKind());
        self::assertNull($this->claimedAtOf($rows[0]));
        self::assertNull($this->reload($post)->getShareKind());
    }

    public function testAPostAfterOneWithRepliesInTheSameAnswerIsStillSettled(): void
    {
        $this->appView->knowsPost(self::postView('self-thread', 1));
        $this->appView->knowsFixture('external');
        $threaded = $this->queuedEntry(self::TECHPRESSO, '<p>Apple has updated.</p>');
        $plain = $this->queuedEntry(self::TISCH, '<p>Read this.<br />' . Bluesky::CARD . '</p>');
        $this->entityManager->flush();

        $this->enricher()->complete([...$this->rowsOf([$threaded]), ...$this->rowsOf([$plain])]);

        self::assertCount(1, $this->appView->requests);
        self::assertSame([], $this->rowsOf([$plain]));
        self::assertCount(1, $this->cardsOf($this->reload($plain)));
        self::assertSame(ShareEnrichmentKind::SelfThread, $this->rowsOf([$threaded])[0]->getKind());
    }

    public function testAPostWithoutRepliesIsDequeuedAsBefore(): void
    {
        $post = self::postView('self-thread', 0);
        $this->appView->knowsPost($post);
        $entry = $this->queuedEntry(self::TECHPRESSO, '<p>Apple has updated.</p>');
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed);

        self::assertSame([], $this->rowsOf([$entry]));
    }

    public function testACardedPostWithRepliesStillGetsItsCardFirst(): void
    {
        $post = self::postView('external', 2);
        $this->appView->knowsPost($post);
        $tisch = $this->queuedEntry(self::TISCH, '<p>Read this.<br />' . Bluesky::CARD . '</p>');
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed);

        $rows = $this->rowsOf([$tisch]);
        self::assertCount(1, $rows);
        self::assertSame(ShareEnrichmentKind::SelfThread, $rows[0]->getKind());
        self::assertCount(1, $this->cardsOf($this->reload($tisch)));
    }

    public function testAQuotePostWithRepliesWhoseTextLinksAPageGetsItsCardAfterItsThread(): void
    {
        $this->feed->classifyAs(SocialNetwork::Bluesky);
        $this->appView->knowsPost(self::postView('record', 1));
        $quote = $this->queuedEntry(self::QUOTING, '<p>Check it out. https://example.com/story</p>');
        $this->entityManager->flush();
        $thread = new FakeReplyThreadSource();
        $thread->readsReplies([new ReplyNode(
            $this->clock->now(),
            static fn (?\DateTimeImmutable $at): EntryCommentModel => new EntryCommentModel(
                new CommentAuthorModel('A', null, null, null),
                'https://bsky.app/profile/test/post/reply',
                $at,
                '<p>And more.</p>',
                true,
                new CommentEmbedsModel([], null),
            ),
            [],
            null,
            true,
        )]);

        $this->enricher()->enrich($this->feed);
        SelfThreadEnrichers::build($this->entityManager, $this->clock, $thread, $this->logger)->enrich($this->feed);

        self::assertSame(ShareKind::Quote, $this->reload($quote)->getShareKind());
        $rows = $this->rowsOf([$quote]);
        self::assertCount(1, $rows);
        self::assertSame(ShareEnrichmentKind::LinkCards, $rows[0]->getKind());
        $posts = SelfThreadEnrichers::threadPosts($this->entityManager)->byEntryIds([$quote->requireId()]);
        self::assertCount(1, $posts[$quote->requireId()]);
    }

    public function testAFeedWithNothingPendingCostsOneQueryAndNoFlush(): void
    {
        $this->entry($this->feed, 'https://example.com/a', '<p>Text.</p>');
        $this->entityManager->flush();
        $this->feed->setTitle('Unflushed title');
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        $this->enricher()->enrich($this->feed);

        self::assertCount(1, $recorder->queries());
        self::assertCount(1, $recorder->queriesMatching('from share_enrichment'));
        self::assertSame([], $this->appView->requests);
        self::assertSame([], $this->indexWriter->upserts);
    }

    public function testIndexesThePostsItFilled(): void
    {
        $this->appView->knowsFixture('external');
        $this->appView->knowsFixture('images');
        $tisch = $this->queuedEntry(self::TISCH, '<p>Read this.</p>');
        $apples = $this->queuedEntry(self::APPLES, '<p>Apples.</p>');
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed);

        self::assertEqualsCanonicalizing([$tisch->requireId(), $apples->requireId()], $this->indexedIds());
        self::assertContains('Read this.', array_column($this->indexWriter->upserted(), 'summary'));
        self::assertCount(1, $this->cardsOf($this->reload($tisch)));
        self::assertSame(0, $this->pendingCount());
    }

    public function testAPostTheAppViewOmitsIsDequeuedAndKeepsItsText(): void
    {
        $post = $this->queuedEntry(self::TEST_POST . 'deleted', '<p>Deleted since.</p>');
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed);

        self::assertSame([], $this->indexWriter->upserts);
        self::assertSame('<p>Deleted since.</p>', $this->reload($post)->getContentHtml());
        self::assertSame(0, $this->pendingCount());
    }

    public function testAPostWhoseLinkIsOnlyInItsTextIsAShareAwaitingItsCard(): void
    {
        $this->appView->knowsPost(['uri' => self::TEST_POST . 'text-link']);
        $post = $this->queuedEntry(
            self::TEST_POST . 'text-link',
            '<p>v0.69.0 <a href="https://github.com/webx-ui/module-blog">github.com/webx-ui/mod…</a></p>',
        );
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed);

        self::assertSame(ShareKind::Link, $this->reload($post)->getShareKind());
        $rows = $this->entityManager->getRepository(ShareEnrichment::class)->findAll();
        self::assertSame([ShareEnrichmentKind::LinkCards], array_map(
            static fn (ShareEnrichment $row): ShareEnrichmentKind => $row->getKind(),
            $rows,
        ));
    }

    public function testAnUnfilledPostSharesNothingOnceDequeued(): void
    {
        $post = $this->queuedEntry(self::TEST_POST . 'deleted', '<p>Deleted since.</p>');
        $post->setShareKind(ShareKind::Link);
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed);

        self::assertNull($this->reload($post)->getShareKind());
    }

    public function testThirtyQueuedPostsTakeTwoRequests(): void
    {
        $this->queuePosts(30);

        $this->enricher()->enrich($this->feed);

        self::assertSame([25, 5], array_map(count(...), $this->appView->requests));
        self::assertSame(0, $this->pendingCount());
    }

    public function testAPassTakesTheOldestHundredPosts(): void
    {
        $guids = $this->queuePosts(101);

        $this->enricher()->enrich($this->feed);

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

        $this->enricher()->enrich($this->feed);

        self::assertSame([], $this->indexWriter->upserts);
        self::assertCount(1, $this->appView->requests);
        self::assertSame(30, $this->pendingCount());
        self::assertSame(300, $this->throttle->remainingSeconds(Bluesky::GET_POSTS));
        self::assertSame(['warning'], array_column($this->logger->records, 'level'));
        self::assertSame('Bluesky AppView gave no usable answer', $this->logger->records[0]['message']);
    }

    public function testTheBackoffSkipsTheNextFeedsPass(): void
    {
        $this->appView->failsWith(new FeedUnreachableException('connection reset'));
        $this->queuePosts(1);
        $this->enricher()->enrich($this->feed);
        $this->appView->recovers();
        $otherFeed = $this->feed('https://bsky.app/profile/bsky.app/rss');
        $this->queuedPost($otherFeed, self::TEST_POST . 'other', '-1 minute');
        $this->entityManager->flush();

        $this->enricher()->enrich($otherFeed);

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

        $this->enricher()->enrich($this->feed);

        self::assertSame([], $this->indexWriter->upserts);
        self::assertCount(2, $this->appView->requests);
        self::assertSame(30, $this->pendingCount());
        self::assertSame(0, $this->throttle->remainingSeconds(Bluesky::GET_POSTS));
        self::assertSame(['warning', 'warning'], array_column($this->logger->records, 'level'));
    }

    public function testAnUnreadableAnswerKeepsItsRowsAndTheNextChunkIsStillAsked(): void
    {
        $this->appView->answersWithBody('<html lang="en">Bad gateway</html>');
        $this->queuePosts(30);

        $this->enricher()->enrich($this->feed);

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

        $this->enricher()->enrich($this->feed);

        self::assertSame([[self::TEST_POST . 'young']], $this->appView->requests);
        self::assertSame([self::TEST_POST . 'other'], $this->pendingGuids());
    }

    public function testAThrottledHostIsNotAsked(): void
    {
        $this->throttle->record(Bluesky::GET_POSTS, 120);
        $this->queuePosts(3);

        $this->enricher()->enrich($this->feed);

        self::assertSame([], $this->appView->requests);
        self::assertSame(3, $this->pendingCount());
    }

    public function testAThrottledAnswerStopsThePass(): void
    {
        $this->appView->failsWith(new FeedThrottledException('HTTP 429', 300));
        $this->queuePosts(30);

        $this->enricher()->enrich($this->feed);

        self::assertCount(1, $this->appView->requests);
        self::assertSame(30, $this->pendingCount());
        self::assertSame(300, $this->throttle->remainingSeconds(Bluesky::GET_POSTS));
    }

    public function testAnImageTheEntryAlreadyHasIsNotOverwritten(): void
    {
        $this->appView->knowsFixture('images');
        $post = $this->queuedEntry(self::APPLES, '<p>Apples.</p>');
        $post->getImage()->storePending('https://example.com/own.jpg', 800, 600);
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed);

        self::assertSame('https://example.com/own.jpg', $this->reload($post)->getImageUrl());
    }

    public function testAnUnexpectedFailureIsLoggedAndFillsNothing(): void
    {
        $this->queuedEntry(self::TISCH, '<p>Text.</p>');
        $this->entityManager->flush();
        $enricher = PostEnrichers::build(
            $this->entityManager,
            $this->clock,
            new AppViewClient(new StubFeedFetcher(), $this->throttle),
            $this->logger,
            $this->indexWriter,
        );

        $enricher->enrich($this->feed);

        self::assertSame(['error'], array_column($this->logger->records, 'level'));
        self::assertSame('Bluesky enrichment failed', $this->logger->records[0]['message']);
        self::assertInstanceOf(\LogicException::class, $this->logger->records[0]['context']['exception']);
        self::assertSame(1, $this->pendingCount());
    }

    public function testAnEntryThatFailsToFillIsLoggedAndDequeuedWhileTheOthersFill(): void
    {
        $this->appView->knowsFixture('external');
        $this->appView->knowsFixture('images');
        $broken = $this->queuedEntry(self::TISCH, '<p>Broken.</p>');
        $apples = $this->queuedEntry(self::APPLES, '<p>Apples.</p>');
        $this->entityManager->flush();
        $enricher = $this->enricherWith(new FailingEmbedWriter(self::TISCH, $this->embedWriter()));

        $enricher->enrich($this->feed);

        self::assertSame([$apples->requireId()], $this->indexedIds());
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
        $post = $this->queuedEntry(self::TISCH, '<p>Text.</p>');
        $this->entityManager->flush();
        $this->entityManager->persist(new ShareEnrichment($post, ShareEnrichmentKind::AppView, $this->clock->now()));

        try {
            $this->enricher()->enrich($this->feed);
            self::fail('A failed flush must be rethrown.');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertFalse($this->entityManager->isOpen());
        self::assertSame([], $this->logger->records);
    }

    public function testAHandedOverRowStaysClaimedWhenItsNewKindFailsToFlush(): void
    {
        $this->appView->knowsPost(Bluesky::untitledLinkView(self::UNTITLED));
        $post = $this->queuedEntry(self::UNTITLED, '<p>Read this.</p>');
        $this->entityManager->flush();
        $this->entityManager->persist(new ShareEnrichment($post, ShareEnrichmentKind::AppView, $this->clock->now()));

        try {
            $this->enricher()->enrich($this->feed);
            self::fail('A failed flush must be rethrown.');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertNotFalse($this->entityManager->getConnection()->fetchOne(
            'SELECT claimed_at FROM share_enrichment WHERE entry_id = ? AND claimed_at IS NOT NULL',
            [$post->requireId()],
        ));
    }

    public function testTheGiveUpCutoffIsUtcWhateverZoneTheClockIsIn(): void
    {
        $this->clock = new MockClock('2026-10-10 12:00:00', 'Europe/Berlin');
        $this->appView->failsWith(new FeedUnreachableException('connection reset'));
        $utc = new \DateTimeZone('UTC');
        $this->storedPost(self::TEST_POST . 'stale', new \DateTimeImmutable('2026-10-07 09:59:59', $utc));
        $this->storedPost(self::TEST_POST . 'young', new \DateTimeImmutable('2026-10-07 10:00:01', $utc));
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed);

        self::assertSame([self::TEST_POST . 'young'], $this->pendingGuids());
    }

    public function testTheRefreshNeverAsksForALinkPost(): void
    {
        $this->appView->knowsFixture('images');
        $apples = $this->queuedEntry(self::APPLES, '<p>Apples.</p>');
        $linkPost = $this->entry($this->feed, 'https://mastodon.test/@a/1', '<p>https://example.com/a</p>');
        $this->entityManager->persist(
            new ShareEnrichment($linkPost, ShareEnrichmentKind::LinkCards, $this->clock->now()),
        );
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed);

        self::assertSame([$apples->requireId()], $this->indexedIds());

        self::assertSame([[self::APPLES]], $this->appView->requests);
        self::assertSame(['https://mastodon.test/@a/1'], $this->pendingGuids());
    }

    public function testARowAnotherWorkerHoldsIsLeftToIt(): void
    {
        $this->appView->knowsFixture('images');
        $held = $this->queuedEntry(self::APPLES, '<p>Apples.</p>');
        $this->entityManager->flush();
        PostEnrichers::enrichments($this->entityManager)->claim($this->rowsOf([$held]), $this->clock->now());

        $this->enricher()->enrich($this->feed);
        $this->enricher()->complete($this->rowsOf([$held]));

        self::assertSame([], $this->appView->requests);
        self::assertSame(1, $this->pendingCount());
    }

    public function testAFailedAnswerReleasesTheRowsForTheNextCall(): void
    {
        $this->appView->failsWith(new FeedUnreachableException('HTTP 400', 400));
        $queued = $this->queuedEntry(self::TISCH, '<p>Read this.</p>');
        $this->entityManager->flush();
        $this->enricher()->complete($this->rowsOf([$queued]));

        $this->enricher()->complete($this->rowsOf([$queued]));

        self::assertCount(2, $this->appView->requests);
        self::assertSame(1, $this->pendingCount());
    }

    public function testCompletingFillsTheRowsAcrossFeedsInOneCall(): void
    {
        $this->appView->knowsFixture('external');
        $this->appView->knowsFixture('images');
        $tisch = $this->queuedEntry(self::TISCH, '<p>Read this.</p>');
        $otherFeed = $this->feed('https://bsky.app/profile/bsky.app/rss');
        $apples = $this->entry($otherFeed, self::APPLES, '<p>Apples.</p>');
        $this->entityManager->persist(new ShareEnrichment($apples, ShareEnrichmentKind::AppView, $this->clock->now()));
        $leftOut = $this->queuedEntry(self::TEST_POST . 'left-out', '<p>Not asked.</p>');
        $this->entityManager->flush();

        $this->enricher()->complete($this->rowsOf([$tisch, $apples]));

        self::assertSame([$tisch->requireId(), $apples->requireId()], $this->indexedIds());
        self::assertCount(1, $this->appView->requests);
        self::assertEqualsCanonicalizing([self::TISCH, self::APPLES], $this->appView->requests[0]);
        self::assertSame([$leftOut->getGuid()], $this->pendingGuids());
        self::assertCount(1, $this->cardsOf($this->reload($tisch)));
    }

    public function testPostsWhoseLinkCameWithoutAPreviewAreHandedToTheLinkCardPass(): void
    {
        $this->appView->knowsPost(Bluesky::untitledLinkView(self::UNTITLED));
        $this->appView->knowsPost(Bluesky::untitledLinkView(self::TEST_POST . 'second'));
        $first = $this->queuedEntry(self::UNTITLED, '<p>Read this.<br />https://example.com/story</p>');
        $second = $this->queuedEntry(self::TEST_POST . 'second', '<p>And this.</p>');
        $this->entityManager->flush();

        $this->enricher()->complete($this->rowsOf([$first, $second]));

        self::assertSame([$first->requireId(), $second->requireId()], $this->indexedIds());

        $rows = PostEnrichers::enrichments($this->entityManager)
            ->findClaimableFor([$first, $second], $this->clock->now());
        self::assertSame([ShareEnrichmentKind::LinkCards, ShareEnrichmentKind::LinkCards], array_map(
            static fn (ShareEnrichment $row): ShareEnrichmentKind => $row->getKind(),
            $rows,
        ));
        self::assertStringNotContainsString('View embedded content', (string) $this->reload($first)->getContentHtml());
    }

    public function testTheLinkCardPassCardsAPostTheAppViewHandedOver(): void
    {
        $this->appView->knowsPost(Bluesky::untitledLinkView(self::UNTITLED));
        $post = $this->queuedEntry(self::UNTITLED, '<p>Read this.<br />https://example.com/story</p>');
        $this->entityManager->flush();
        $this->enricher()->complete($this->rowsOf([$post]));

        $this->linkCardPass()->complete($this->rowsOf([$post]), LinkCardBudgetModel::fetches(5));

        self::assertSame(['https://example.com/story'], $this->cardUrls($post));
    }

    public function testAnotherLinkBesideACardedEmbedGetsItsCardAfterTheEmbeds(): void
    {
        $this->appView->knowsFixture('external');
        $post = $this->queuedEntry(self::TISCH, '<p>Also https://example.com/story<br />' . Bluesky::CARD . '</p>');
        $this->entityManager->flush();
        $this->enricher()->complete($this->rowsOf([$post]));

        $this->linkCardPass()->complete($this->rowsOf([$post]), LinkCardBudgetModel::fetches(5));

        self::assertSame([Bluesky::CARD, 'https://example.com/story'], $this->cardUrls($post));
    }

    public function testCompletingNoRowsAsksNothing(): void
    {
        $this->enricher()->complete([]);

        self::assertSame([], $this->appView->requests);
    }

    public function testEnrichingEntriesOnAThrottledHostLeavesThemQueued(): void
    {
        $this->throttle->record(Bluesky::GET_POSTS, 120);
        $queued = $this->queuedEntry(self::TISCH, '<p>Read this.</p>');
        $this->entityManager->flush();

        $this->enricher()->complete($this->rowsOf([$queued]));

        self::assertSame([], $this->appView->requests);
        self::assertSame(1, $this->pendingCount());
        self::assertCount(
            1,
            PostEnrichers::enrichments($this->entityManager)->findClaimableFor([$queued], $this->clock->now()),
        );
    }

    public function testEnrichingEntriesThatTheAppViewFailsLeavesThemQueuedAndWarns(): void
    {
        $this->appView->failsWith(new FeedUnreachableException('connection reset'));
        $queued = $this->queuedEntry(self::TISCH, '<p>Read this.</p>');
        $this->entityManager->flush();

        $this->enricher()->complete($this->rowsOf([$queued]));

        self::assertSame(1, $this->pendingCount());
        self::assertSame(['warning'], array_column($this->logger->records, 'level'));
        self::assertSame('Bluesky AppView gave no usable answer', $this->logger->records[0]['message']);
        self::assertInstanceOf(FeedUnreachableException::class, $this->logger->records[0]['context']['exception']);
    }

    public function testAnUnexpectedFailureWhileEnrichingEntriesIsLoggedAndFillsNothing(): void
    {
        $queued = $this->queuedEntry(self::TISCH, '<p>Text.</p>');
        $this->entityManager->flush();
        $enricher = PostEnrichers::build(
            $this->entityManager,
            $this->clock,
            new AppViewClient(new StubFeedFetcher(), $this->throttle),
            $this->logger,
            $this->indexWriter,
        );

        $enricher->complete($this->rowsOf([$queued]));

        self::assertSame(['error'], array_column($this->logger->records, 'level'));
        self::assertSame('Bluesky enrichment failed', $this->logger->records[0]['message']);
        self::assertSame(1, $this->pendingCount());
    }

    /** @return list<int> */
    private function indexedIds(): array
    {
        return array_column($this->indexWriter->upserted(), 'id');
    }

    private function linkCardPass(): ShareLinkCards
    {
        $page = new MockResponse(
            '<html lang="en"><head><meta property="og:title" content="The story"></head></html>',
            ['response_headers' => ['content-type' => 'text/html']],
        );

        return ShareLinkCardsWiring::build($this->entityManager, new MockHttpClient($page), $this->clock);
    }

    /** @return list<string> */
    private function cardUrls(Entry $entry): array
    {
        $cards = PostEnrichers::linkCards($this->entityManager)->contentsByEntryIds([$entry->requireId()]);

        return array_map(static fn (LinkCardContent $card): string => $card->url, $cards[$entry->requireId()] ?? []);
    }

    private function enricher(): PostEnricher
    {
        return PostEnrichers::build(
            $this->entityManager,
            $this->clock,
            new AppViewClient($this->appView, $this->throttle),
            $this->logger,
            $this->indexWriter,
        );
    }

    private function enricherWith(EntryEmbedWriterInterface $embedWriter): PostEnricher
    {
        $naiveUtcClock = new NaiveUtcClock($this->clock);

        return new PostEnricher(
            $this->entityManager,
            PostEnrichers::enrichments($this->entityManager),
            new AppViewClient($this->appView, $this->throttle),
            $embedWriter,
            new EntryIndexer($this->indexWriter, new NullLogger()),
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
            PostEnrichers::linkCards($this->entityManager),
        );
    }

    /** @return list<LinkCard> */
    private function cardsOf(Entry $entry): array
    {
        return $this->entityManager->getRepository(LinkCard::class)->findBy(['entry' => $entry]);
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<ShareEnrichment>
     */
    private function rowsOf(array $entries): array
    {
        return PostEnrichers::enrichments($this->entityManager)->findBy(['entry' => $entries], ['queuedAt' => 'ASC']);
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

    private function queuedEntry(string $guid, string $contentHtml): Entry
    {
        $entry = $this->entry($this->feed, $guid, $contentHtml);
        $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::AppView, $this->clock->now()));

        return $entry;
    }

    private function queuedPost(Feed $feed, string $guid, string $queuedBefore): void
    {
        $this->storedPost($guid, $this->clock->now()->modify($queuedBefore), $feed);
    }

    private function storedPost(string $guid, \DateTimeImmutable $queuedAt, ?Feed $feed = null): void
    {
        $entry = $this->entry($feed ?? $this->feed, $guid, '<p>Text.</p>');
        $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::AppView, $queuedAt));
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

    /** @return array<mixed> */
    private static function postView(string $fixture, int $replyCount): array
    {
        /** @var array{posts: list<array<mixed>>} $answer */
        $answer = json_decode(Bluesky::fixture($fixture), true, flags: \JSON_THROW_ON_ERROR);
        $post = $answer['posts'][0];
        $post['replyCount'] = $replyCount;

        return $post;
    }

    private function claimedAtOf(ShareEnrichment $row): mixed
    {
        return $this->entityManager->getConnection()->fetchOne(
            'SELECT claimed_at FROM share_enrichment WHERE id = ?',
            [$row->requireId()],
        ) ?: null;
    }

    private function pendingCount(): int
    {
        return $this->entityManager->getRepository(ShareEnrichment::class)->count([]);
    }

    /** @return list<string> */
    private function pendingGuids(): array
    {
        return array_map(
            static fn (ShareEnrichment $pending): string => $pending->getEntry()->getGuid(),
            $this->entityManager->getRepository(ShareEnrichment::class)->findAll(),
        );
    }
}
