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
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\CommentEmbedsModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Pass\ReplyNode;
use App\Service\Social\SelfThreadEnricher;
use App\Tests\DbTestCase;
use App\Tests\Support\FakeReplyThreadSource;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\SelfThreadEnrichers;
use Symfony\Component\Clock\MockClock;

final class SelfThreadEnricherTest extends DbTestCase
{
    use ReloadsEntities;

    private const string POST = 'at://did:plc:test/app.bsky.feed.post/root';

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
        $this->thread->readsReplies([self::ownReply('<p>one</p>', '2026-10-17 10:00:00')]);

        $this->enricher()->enrich($this->feed);

        $row = $this->onlyRow();
        self::assertSame(ShareEnrichmentKind::LinkCards, $row->getKind());
        self::assertNull($this->claimedAtOf($row));
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

    private function enricher(): SelfThreadEnricher
    {
        return SelfThreadEnrichers::build($this->entityManager, $this->clock, $this->thread, $this->logger);
    }

    private function queuedEntry(string $queuedAt = 'now'): Entry
    {
        $now = new \DateTimeImmutable('2026-10-17 12:00:00');
        $entry = new Entry($this->feed, self::POST, null, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->persist(new ShareEnrichment(
            $entry,
            ShareEnrichmentKind::SelfThread,
            $now->modify($queuedAt),
        ));
        $this->entityManager->flush();

        return $entry;
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
