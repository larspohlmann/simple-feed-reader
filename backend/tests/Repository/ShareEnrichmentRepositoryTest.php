<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Repository\ShareEnrichmentRepository;
use App\Tests\DbTestCase;

final class ShareEnrichmentRepositoryTest extends DbTestCase
{
    private const string NOW = '2026-10-11 12:00:00';

    public function testFindsAFeedsClaimableAppViewRowsOldestFirstUpToTheLimit(): void
    {
        $feed = $this->feed('https://bsky.app/profile/a/rss');
        $other = $this->feed('https://bsky.app/profile/b/rss');
        $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/new', '2026-10-10 11:00:00');
        $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/old', '2026-10-10 09:00:00');
        $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/mid', '2026-10-10 10:00:00');
        $this->queued($feed, 'https://mastodon.test/@a/1', '2026-10-10 08:00:00', ShareEnrichmentKind::LinkCards);
        $this->queued($other, 'at://did:plc:b/app.bsky.feed.post/elsewhere', '2026-10-10 08:00:00');
        $this->entityManager->flush();

        $rows = $this->repository()->findClaimableForFeed($feed, ShareEnrichmentKind::AppView, $this->now(), 2);

        self::assertSame(
            ['at://did:plc:a/app.bsky.feed.post/old', 'at://did:plc:a/app.bsky.feed.post/mid'],
            self::guidsOf($rows),
        );
    }

    public function testFindsClaimableLinkCardRowsOfEveryFeedOldestFirstUpToTheLimit(): void
    {
        $mastodon = $this->feed('https://mastodon.test/@a.rss');
        $news = $this->feed('https://news.test/feed');
        $this->queued($mastodon, 'https://mastodon.test/@a/new', '2026-10-10 11:00:00', ShareEnrichmentKind::LinkCards);
        $this->queued($news, 'https://news.test/old', '2026-10-10 09:00:00', ShareEnrichmentKind::LinkCards);
        $this->queued($mastodon, 'https://mastodon.test/@a/mid', '2026-10-10 10:00:00', ShareEnrichmentKind::LinkCards);
        $this->queued($mastodon, 'at://did:plc:a/app.bsky.feed.post/oldest', '2026-10-10 08:00:00');
        $held = $this->queued($news, 'https://news.test/held', '2026-10-10 07:00:00', ShareEnrichmentKind::LinkCards);
        $this->entityManager->flush();
        $this->repository()->claim([$held], $this->now());

        $rows = $this->repository()->findClaimableLinkCards($this->now(), 2);

        self::assertSame(['https://news.test/old', 'https://mastodon.test/@a/mid'], self::guidsOf($rows));
    }

    public function testDeletesOnlyTheFeedsAppViewRowsQueuedBeforeTheCutoff(): void
    {
        $feed = $this->feed('https://bsky.app/profile/a/rss');
        $other = $this->feed('https://bsky.app/profile/b/rss');
        $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/old', '2026-10-10 09:59:59');
        $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/at-cutoff', '2026-10-10 10:00:00');
        $this->queued($feed, 'https://mastodon.test/@a/1', '2026-10-01 08:00:00', ShareEnrichmentKind::LinkCards);
        $this->queued($other, 'at://did:plc:b/app.bsky.feed.post/elsewhere', '2026-10-10 08:00:00');
        $this->entityManager->flush();

        $this->repository()->deleteQueuedBefore(
            $feed,
            ShareEnrichmentKind::AppView,
            new \DateTimeImmutable('2026-10-10 10:00:00'),
        );

        self::assertSame(
            ['https://mastodon.test/@a/1', 'at://did:plc:a/app.bsky.feed.post/at-cutoff'],
            self::guidsOf($this->repository()->findClaimableFor($this->entriesOf($feed), $this->now())),
        );
        self::assertCount(
            1,
            $this->repository()->findClaimableForFeed($other, ShareEnrichmentKind::AppView, $this->now(), 10),
        );
    }

    public function testTellsWhetherAFeedHasAnAppViewRow(): void
    {
        $feed = $this->feed('https://bsky.app/profile/a/rss');
        $linkPosts = $this->feed('https://mastodon.test/@a.rss');
        $empty = $this->feed('https://bsky.app/profile/c/rss');
        $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/one', '2026-10-10 09:00:00');
        $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/two', '2026-10-10 09:00:00');
        $this->queued($linkPosts, 'https://mastodon.test/@a/1', '2026-10-10 09:00:00', ShareEnrichmentKind::LinkCards);
        $this->entityManager->flush();

        self::assertTrue($this->repository()->hasPendingForFeed($feed, ShareEnrichmentKind::AppView));
        self::assertFalse($this->repository()->hasPendingForFeed($linkPosts, ShareEnrichmentKind::AppView));
        self::assertFalse($this->repository()->hasPendingForFeed($empty, ShareEnrichmentKind::AppView));
    }

    public function testKeepsEachKindOfFeedQueueApart(): void
    {
        $feed = $this->feed('https://bsky.app/profile/a/rss');
        $appView = $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/one', '2026-10-10 09:00:00');
        $this->queued(
            $feed,
            'at://did:plc:a/app.bsky.feed.post/thread',
            '2026-10-10 09:00:00',
            ShareEnrichmentKind::SelfThread,
        );
        $this->entityManager->flush();

        self::assertTrue($this->repository()->hasPendingForFeed($feed, ShareEnrichmentKind::SelfThread));
        self::assertSame(
            ['at://did:plc:a/app.bsky.feed.post/thread'],
            self::guidsOf($this->repository()->findClaimableForFeed(
                $feed,
                ShareEnrichmentKind::SelfThread,
                $this->now(),
                10,
            )),
        );

        $this->repository()->deleteQueuedBefore(
            $feed,
            ShareEnrichmentKind::SelfThread,
            new \DateTimeImmutable('2026-10-10 10:00:00'),
        );

        self::assertFalse($this->repository()->hasPendingForFeed($feed, ShareEnrichmentKind::SelfThread));
        self::assertTrue($this->repository()->hasPendingForFeed($feed, ShareEnrichmentKind::AppView));
        self::assertSame(
            [$appView],
            $this->repository()->findClaimableForFeed($feed, ShareEnrichmentKind::AppView, $this->now(), 10),
        );
    }

    public function testARowGoesWithItsEntry(): void
    {
        $feed = $this->feed('https://bsky.app/profile/a/rss');
        $entry = $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/gone', '2026-10-10 09:00:00')->getEntry();
        $this->entityManager->flush();
        $connection = $this->entityManager->getConnection();

        $connection->executeStatement('DELETE FROM entry WHERE id = ?', [$entry->requireId()]);

        self::assertSame([], $this->repository()->findAll());
    }

    public function testOfTwoClaimsOnOneRowOnlyOneGetsIt(): void
    {
        $row = $this->queuedLinkPost($this->feed('https://mastodon.test/@a.rss'), 'https://mastodon.test/@a/1');
        $this->entityManager->flush();

        $first = $this->repository()->claim([$row], $this->now());
        $second = $this->repository()->claim([$row], $this->now());

        self::assertSame([$row], $first);
        self::assertSame([], $second);
    }

    public function testAClaimHoldsTheRowForItsLeaseOnly(): void
    {
        $feed = $this->feed('https://mastodon.test/@a.rss');
        $row = $this->queued($feed, 'https://mastodon.test/@a/1', '2026-10-11 11:00:00');
        $this->entityManager->flush();
        $this->repository()->claim([$row], $this->now());

        self::assertSame([], $this->repository()->claim([$row], $this->now()->modify('+60 seconds')));
        self::assertSame(
            [],
            $this->repository()->findClaimableFor([$row->getEntry()], $this->now()->modify('+60 seconds')),
        );
        self::assertSame([$row], $this->repository()->claim([$row], $this->now()->modify('+61 seconds')));
    }

    public function testAHolderWhoseLeaseWasTakenOverChangesNothing(): void
    {
        $row = $this->queuedLinkPost($this->feed('https://mastodon.test/@a.rss'), 'https://mastodon.test/@a/1');
        $this->entityManager->flush();
        $stale = $this->now();
        $current = $this->now()->modify('+61 seconds');
        $this->repository()->claim([$row], $stale);
        $this->repository()->claim([$row], $current);

        $this->repository()->markDone([$row], $stale);
        $this->repository()->release([$row], $stale);

        self::assertSame([], $this->repository()->stillHeld([$row], $stale));
        self::assertSame([$row], $this->repository()->stillHeld([$row], $current));
        self::assertSame(
            [$row->getEntry()->requireId()],
            $this->repository()->pendingEntryIdsAmong([$row->getEntry()->requireId()]),
        );
    }

    public function testAReleasedRowCanBeClaimedAgainAtOnce(): void
    {
        $feed = $this->feed('https://bsky.app/profile/a/rss');
        $row = $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/one', '2026-10-11 11:00:00');
        $this->entityManager->flush();
        $this->repository()->claim([$row], $this->now());

        $this->repository()->release([$row], $this->now());

        self::assertSame([$row], $this->repository()->claim([$row], $this->now()));
    }

    public function testADoneRowIsNeitherPendingNorClaimable(): void
    {
        $feed = $this->feed('https://mastodon.test/@a.rss');
        $done = $this->queuedLinkPost($feed, 'https://mastodon.test/@a/1', '2026-10-11 10:00:00');
        $waiting = $this->queuedLinkPost($feed, 'https://mastodon.test/@a/2');
        $plain = $this->entry($feed, 'https://mastodon.test/@a/3');
        $this->entityManager->flush();
        $this->repository()->claim([$done], $this->now());

        $this->repository()->markDone([$done], $this->now());

        self::assertSame(
            [$waiting->getEntry()->requireId()],
            $this->repository()->pendingEntryIdsAmong([
                $done->getEntry()->requireId(),
                $waiting->getEntry()->requireId(),
                $plain->requireId(),
            ]),
        );
        self::assertSame([], $this->repository()->claim([$done], $this->now()->modify('+1 day')));
        self::assertSame([$waiting], $this->repository()->findClaimableFor($this->entriesOf($feed), $this->now()));
    }

    public function testAHeldRowIsStillPending(): void
    {
        $row = $this->queuedLinkPost($this->feed('https://mastodon.test/@a.rss'), 'https://mastodon.test/@a/1');
        $this->entityManager->flush();

        $this->repository()->claim([$row], $this->now());

        self::assertSame(
            [$row->getEntry()->requireId()],
            $this->repository()->pendingEntryIdsAmong([$row->getEntry()->requireId()]),
        );
    }

    /**
     * @param list<ShareEnrichment> $rows
     *
     * @return list<string>
     */
    private static function guidsOf(array $rows): array
    {
        return array_map(static fn (ShareEnrichment $pending): string => $pending->getEntry()->getGuid(), $rows);
    }

    /** @return list<Entry> */
    private function entriesOf(Feed $feed): array
    {
        return $this->entityManager->getRepository(Entry::class)->findBy(['feed' => $feed]);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);

        return $feed;
    }

    private function queued(
        Feed $feed,
        string $guid,
        string $queuedAt,
        ShareEnrichmentKind $kind = ShareEnrichmentKind::AppView,
    ): ShareEnrichment {
        $pending = new ShareEnrichment($this->entry($feed, $guid), $kind, new \DateTimeImmutable($queuedAt));
        $this->entityManager->persist($pending);

        return $pending;
    }

    private function queuedLinkPost(Feed $feed, string $guid, string $queuedAt = '2026-10-11 11:00:00'): ShareEnrichment
    {
        return $this->queued($feed, $guid, $queuedAt, ShareEnrichmentKind::LinkCards);
    }

    private function entry(Feed $feed, string $guid): Entry
    {
        $entry = new Entry(
            $feed,
            $guid,
            null,
            'Post',
            new \DateTimeImmutable('2026-10-10 08:00:00'),
            new \DateTimeImmutable('2026-10-10 08:00:00'),
        );
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function repository(): ShareEnrichmentRepository
    {
        $repository = self::getContainer()->get(ShareEnrichmentRepository::class);
        self::assertInstanceOf(ShareEnrichmentRepository::class, $repository);

        return $repository;
    }
}
