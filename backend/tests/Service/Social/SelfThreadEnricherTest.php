<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCardContent;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Enum\ShareKind;
use App\Enum\SocialNetwork;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\CommentEmbedsModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Pass\ReplyNode;
use App\Service\Social\SelfThreadEnricher;
use App\Tests\DbTestCase;
use App\Tests\Support\FakeReplyThreadSource;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\SelfThreadEnrichers;
use Symfony\Component\Clock\MockClock;

final class SelfThreadEnricherTest extends DbTestCase
{
    use ReloadsEntities;

    private const string POST = 'at://did:plc:test/app.bsky.feed.post/root';
    private const string LINKED_PAGE = 'https://example.com/story';
    private const string LINKED_TEXT = '<p>Read this. ' . self::LINKED_PAGE . '</p>';

    private MockClock $clock;
    private FakeReplyThreadSource $thread;
    private RecordingLogger $logger;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-17 12:00:00', 'UTC');
        $this->thread = new FakeReplyThreadSource();
        $this->logger = new RecordingLogger();
        $this->feed = new Feed('https://bsky.app/profile/test/rss');
        $this->feed->classifyAs(SocialNetwork::Bluesky);
        $this->entityManager->persist($this->feed);
    }

    public function testFoldsTheChainAndDequeues(): void
    {
        $entry = $this->queuedEntry();
        $this->thread->readsReplies([
            self::ownReply('<p>one</p>', '2026-10-17 10:00:00', [
                self::ownReply('<p>two</p>', '2026-10-17 10:01:00', [], new LinkCardContent(
                    'https://a.example/',
                    'A',
                    null,
                    null,
                    'a.example',
                )),
            ]),
        ]);

        $this->enricher()->enrich($this->feed);

        $posts = SelfThreadEnrichers::threadPosts($this->entityManager)->byEntryIds([$entry->requireId()]);
        self::assertCount(2, $posts[$entry->requireId()]);
        self::assertSame(0, $this->entityManager->getRepository(ShareEnrichment::class)->count([]));
        self::assertContains('inPublic ' . self::POST, $this->thread->calls);
    }

    public function testARootThatSharesALinkMovesOnToLinkCards(): void
    {
        $entry = $this->queuedEntry();
        $entry->setShareKind(ShareKind::Link);
        $entry->setContentHtml(self::LINKED_TEXT);
        $this->thread->readsReplies([self::ownReply('<p>one</p>', '2026-10-17 10:00:00')]);

        $this->enricher()->enrich($this->feed);

        $row = $this->onlyRow();
        self::assertSame(ShareEnrichmentKind::LinkCards, $row->getKind());
        self::assertNull($this->claimedAtOf($row));
    }

    public function testAQuotedPostWhoseTextLinksAPageMovesOnToLinkCards(): void
    {
        $entry = $this->queuedEntry();
        $entry->setShareKind(ShareKind::Quote);
        $entry->setContentHtml(self::LINKED_TEXT);
        $this->thread->readsReplies([self::ownReply('<p>one</p>', '2026-10-17 10:00:00')]);

        $this->enricher()->enrich($this->feed);

        self::assertSame(ShareEnrichmentKind::LinkCards, $this->onlyRow()->getKind());
        $posts = SelfThreadEnrichers::threadPosts($this->entityManager)->byEntryIds([$entry->requireId()]);
        self::assertCount(1, $posts[$entry->requireId()]);
    }

    public function testARootThatSharesNothingIsDroppedThoughItsTextLinksAPage(): void
    {
        $entry = $this->queuedEntry();
        $entry->setContentHtml(self::LINKED_TEXT);
        $this->entityManager->flush();
        $this->thread->readsReplies([self::ownReply('<p>one</p>', '2026-10-17 10:00:00')]);

        $this->enricher()->enrich($this->feed);

        self::assertSame([$entry->requireId()], $this->foldedEntryIds());
        self::assertSame(0, $this->entityManager->getRepository(ShareEnrichment::class)->count([]));
    }

    public function testALinkRootWhoseLinkIsAlreadyCardedIsDequeued(): void
    {
        $entry = $this->queuedEntry();
        $entry->setShareKind(ShareKind::Link);
        $entry->setContentHtml(self::LINKED_TEXT);
        PostEnrichers::linkCards($this->entityManager)->append($entry, [
            new LinkCardContent(self::LINKED_PAGE, 'Story', null, null, 'example.com'),
        ]);
        $this->entityManager->flush();
        $this->thread->readsReplies([self::ownReply('<p>one</p>', '2026-10-17 10:00:00')]);

        $this->enricher()->enrich($this->feed);

        self::assertSame(0, $this->entityManager->getRepository(ShareEnrichment::class)->count([]));
    }

    public function testARowPastTheGiveUpWindowWhoseRootLinksAPageMovesOnToLinkCardsUnasked(): void
    {
        $entry = $this->queuedEntry('-4 days');
        $entry->setShareKind(ShareKind::Link);
        $entry->setContentHtml(self::LINKED_TEXT);
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed);

        self::assertSame([], $this->thread->calls);
        $row = $this->onlyRow();
        self::assertSame(ShareEnrichmentKind::LinkCards, $row->getKind());
        self::assertNull($this->claimedAtOf($row));
    }

    public function testAnEntryWithoutASocialAddressIsDropped(): void
    {
        $this->queuedEntry(guid: 'https://example.com/not-a-post');

        $this->enricher()->enrich($this->feed);

        self::assertSame([], $this->thread->calls);
        self::assertSame(0, $this->entityManager->getRepository(ShareEnrichment::class)->count([]));
    }

    public function testAnEntryWithoutASocialAddressWhoseRootLinksAPageMovesOnToLinkCards(): void
    {
        $entry = $this->queuedEntry(guid: 'https://example.com/not-a-post');
        $entry->setShareKind(ShareKind::Link);
        $entry->setContentHtml(self::LINKED_TEXT);
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed);

        self::assertSame([], $this->thread->calls);
        self::assertSame(ShareEnrichmentKind::LinkCards, $this->onlyRow()->getKind());
    }

    public function testAThrottledNetworkLeavesTheRowQueued(): void
    {
        $this->queuedEntry();
        $this->thread->throttlePublicThreadFor(30);

        $this->enricher()->enrich($this->feed);

        self::assertSame([], $this->thread->calls);
        $row = $this->onlyRow();
        self::assertSame(ShareEnrichmentKind::SelfThread, $row->getKind());
        self::assertNull($this->claimedAtOf($row));
    }

    public function testAnUnreachableThreadFoldsNothingAndDequeues(): void
    {
        $entry = $this->queuedEntry();
        $this->thread->failInPublicWith(new SocialNetworkUnreachableException('down'));

        $this->enricher()->enrich($this->feed);

        $posts = SelfThreadEnrichers::threadPosts($this->entityManager)->byEntryIds([$entry->requireId()]);
        self::assertSame([], $posts);
        self::assertSame(0, $this->entityManager->getRepository(ShareEnrichment::class)->count([]));
        self::assertSame(['warning'], array_column($this->logger->records, 'level'));
    }

    public function testAThreadGoneFromTheNetworkFoldsNothingAndDequeues(): void
    {
        $entry = $this->queuedEntry();
        $this->thread->failInPublicWith(new SocialPostNotFoundException('gone'));

        $this->enricher()->enrich($this->feed);

        $this->assertDequeuedUnfoldedWithAWarning($entry);
    }

    public function testAnUnreadableThreadFoldsNothingAndDequeues(): void
    {
        $entry = $this->queuedEntry();
        $this->thread->failRepliesWith(new CommentsUnavailableException('no descendants'));

        $this->enricher()->enrich($this->feed);

        $this->assertDequeuedUnfoldedWithAWarning($entry);
    }

    public function testAThrottleDuringTheCallLeavesTheRowQueued(): void
    {
        $entry = $this->queuedEntry();
        $this->thread->failInPublicWith(new SocialNetworkThrottledException('slow down', 30));

        $this->enricher()->enrich($this->feed);

        $row = $this->onlyRow();
        self::assertSame(ShareEnrichmentKind::SelfThread, $row->getKind());
        self::assertNull($this->claimedAtOf($row));
        $posts = SelfThreadEnrichers::threadPosts($this->entityManager)->byEntryIds([$entry->requireId()]);
        self::assertSame([], $posts);
        self::assertSame([], $this->logger->records);
    }

    public function testARowQueuedExactlyAtTheGiveUpCutoffIsStillAsked(): void
    {
        $entry = $this->queuedEntry('-3 days');
        $this->thread->readsReplies([self::ownReply('<p>one</p>', '2026-10-17 10:00:00')]);

        $this->enricher()->enrich($this->feed);

        self::assertSame([$entry->requireId()], $this->foldedEntryIds());
    }

    public function testAFeedWithNothingQueuedCostsOneQuery(): void
    {
        $this->entityManager->flush();
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        $this->enricher()->enrich($this->feed);

        self::assertCount(1, $recorder->queries());
        self::assertSame([], $this->logger->records);
    }

    public function testRowsPastTheGiveUpWindowAreDropped(): void
    {
        $this->queuedEntry('-4 days');

        $this->enricher()->enrich($this->feed);

        self::assertSame([], $this->thread->calls);
        self::assertSame(0, $this->entityManager->getRepository(ShareEnrichment::class)->count([]));
    }

    public function testAnEntryAlreadyFoldedIsNotFoldedAgain(): void
    {
        $entry = $this->queuedEntry();
        $this->thread->readsReplies([self::ownReply('<p>one</p>', '2026-10-17 10:00:00')]);
        $this->enricher()->enrich($this->feed);
        $this->entityManager->persist(
            new ShareEnrichment($entry, ShareEnrichmentKind::SelfThread, $this->clock->now()),
        );
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed);

        $posts = SelfThreadEnrichers::threadPosts($this->entityManager)->byEntryIds([$entry->requireId()]);
        self::assertCount(1, $posts[$entry->requireId()]);
        self::assertSame(0, $this->entityManager->getRepository(ShareEnrichment::class)->count([]));
        self::assertSame([], $this->logger->records);
    }

    public function testAPassStartsNoThreadThatCouldOutlastTheLeaseAndReleasesTheRest(): void
    {
        $first = $this->queuedEntry('-3 minutes', self::POST . '-1');
        $this->queuedEntry('-2 minutes', self::POST . '-2');
        $this->queuedEntry('-1 minute', self::POST . '-3');
        $this->thread->readsReplies([self::ownReply('<p>one</p>', '2026-10-17 10:00:00')]);
        $this->thread->duringEachPublicAsk(fn () => $this->clock->sleep(31));

        $this->enricher()->enrich($this->feed);

        self::assertSame(['inPublic ' . self::POST . '-1'], $this->publicAsks());
        self::assertSame([$first->requireId()], $this->foldedEntryIds());
        self::assertSame([null, null], $this->claimsOfRemainingRows());
    }

    public function testAPassKeepsStartingThreadsWhileTheyFitTheLease(): void
    {
        $this->queuedEntry('-2 minutes', self::POST . '-1');
        $this->queuedEntry('-1 minute', self::POST . '-2');
        $this->thread->readsReplies([self::ownReply('<p>one</p>', '2026-10-17 10:00:00')]);
        $this->thread->duringEachPublicAsk(fn () => $this->clock->sleep(30));

        $this->enricher()->enrich($this->feed);

        self::assertCount(2, $this->publicAsks());
        self::assertSame(0, $this->entityManager->getRepository(ShareEnrichment::class)->count([]));
    }

    public function testCompletingFoldsAtMostTwoThreads(): void
    {
        $first = $this->queuedEntry('-3 minutes', self::POST . '-1');
        $second = $this->queuedEntry('-2 minutes', self::POST . '-2');
        $this->queuedEntry('-1 minute', self::POST . '-3');
        $this->thread->readsReplies([self::ownReply('<p>one</p>', '2026-10-17 10:00:00')]);

        $queued = PostEnrichers::enrichments($this->entityManager)->findBy([], ['queuedAt' => 'ASC']);

        $this->enricher()->complete($queued);

        self::assertCount(2, $this->publicAsks());
        self::assertEqualsCanonicalizing([$first->requireId(), $second->requireId()], $this->foldedEntryIds());
        self::assertSame([null], $this->claimsOfRemainingRows());
    }

    public function testARowWhoseLeaseAnotherWorkerTookOverIsNotFolded(): void
    {
        $this->queuedEntry();
        $this->thread->readsReplies([self::ownReply('<p>one</p>', '2026-10-17 10:00:00')]);
        $this->thread->duringEachPublicAsk(function (): void {
            $this->entityManager->getConnection()->executeStatement(
                "UPDATE share_enrichment SET claimed_at = '2026-10-17 12:01:30'",
            );
        });

        $this->enricher()->enrich($this->feed);

        self::assertSame([], $this->foldedEntryIds());
        $row = $this->onlyRow();
        self::assertSame(ShareEnrichmentKind::SelfThread, $row->getKind());
        self::assertSame('2026-10-17 12:01:30', $this->claimedAtOf($row));
    }

    private function enricher(): SelfThreadEnricher
    {
        return SelfThreadEnrichers::build($this->entityManager, $this->clock, $this->thread, $this->logger);
    }

    private function queuedEntry(string $queuedAt = 'now', string $guid = self::POST): Entry
    {
        $now = new \DateTimeImmutable('2026-10-17 12:00:00');
        $entry = new Entry($this->feed, $guid, null, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->persist(new ShareEnrichment(
            $entry,
            ShareEnrichmentKind::SelfThread,
            $now->modify($queuedAt),
        ));
        $this->entityManager->flush();

        return $entry;
    }

    private function assertDequeuedUnfoldedWithAWarning(Entry $entry): void
    {
        $posts = SelfThreadEnrichers::threadPosts($this->entityManager)->byEntryIds([$entry->requireId()]);
        self::assertSame([], $posts);
        self::assertSame(0, $this->entityManager->getRepository(ShareEnrichment::class)->count([]));
        self::assertSame(['warning'], array_column($this->logger->records, 'level'));
        $context = $this->logger->records[0]['context'];
        self::assertSame(self::POST, $context['address'] ?? null);
        self::assertInstanceOf(\RuntimeException::class, $context['exception'] ?? null);
    }

    /** @return list<string> */
    private function publicAsks(): array
    {
        return array_values(array_filter(
            $this->thread->calls,
            static fn (string $call): bool => str_starts_with($call, 'inPublic '),
        ));
    }

    /** @return list<int> */
    private function foldedEntryIds(): array
    {
        return array_map(
            static fn (mixed $entryId): int => \is_numeric($entryId) ? (int) $entryId : 0,
            $this->entityManager->getConnection()->fetchFirstColumn(
                'SELECT DISTINCT entry_id FROM thread_post ORDER BY entry_id',
            ),
        );
    }

    /** @return list<mixed> the claim of each row still queued, oldest first */
    private function claimsOfRemainingRows(): array
    {
        return array_map(
            static fn (mixed $claimedAt): mixed => $claimedAt ?: null,
            $this->entityManager->getConnection()->fetchFirstColumn(
                'SELECT claimed_at FROM share_enrichment WHERE kind = ? ORDER BY queued_at',
                [ShareEnrichmentKind::SelfThread->value],
            ),
        );
    }

    private function onlyRow(): ShareEnrichment
    {
        $rows = $this->entityManager->getRepository(ShareEnrichment::class)->findAll();
        self::assertCount(1, $rows);

        return $this->reload($rows[0]);
    }

    private function claimedAtOf(ShareEnrichment $row): mixed
    {
        return $this->entityManager->getConnection()->fetchOne(
            'SELECT claimed_at FROM share_enrichment WHERE id = ?',
            [$row->requireId()],
        ) ?: null;
    }

    /** @param list<ReplyNode> $replies */
    private static function ownReply(
        string $html,
        string $publishedAt,
        array $replies = [],
        ?LinkCardContent $card = null,
    ): ReplyNode {
        return new ReplyNode(
            new \DateTimeImmutable($publishedAt),
            static fn (?\DateTimeImmutable $at): EntryCommentModel => new EntryCommentModel(
                new CommentAuthorModel('A', null, null, null),
                'https://bsky.app/profile/test/post/' . md5($html),
                $at,
                $html,
                true,
                new CommentEmbedsModel([], $card),
            ),
            $replies,
            null,
            true,
        );
    }
}
