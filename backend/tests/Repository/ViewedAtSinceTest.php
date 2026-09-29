<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryStateRepository;
use App\Tests\DbTestCase;

/**
 * The read side of the reading-activity chart (#896): every article open at or
 * after a lower bound, scoped to the user and to feeds still subscribed to.
 */
final class ViewedAtSinceTest extends DbTestCase
{
    private function repository(): EntryStateRepository
    {
        $repository = $this->entityManager->getRepository(EntryState::class);
        self::assertInstanceOf(EntryStateRepository::class, $repository);

        return $repository;
    }

    private function entry(Feed $feed, string $guid): Entry
    {
        $createdAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $entry = new Entry($feed, $guid, null, $guid, $createdAt, $createdAt);
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function subscribedFeed(User $user, string $url, \DateTimeImmutable $when): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, $when));

        return $feed;
    }

    private function openedAt(User $user, Feed $feed, string $guid, \DateTimeImmutable $when): void
    {
        $state = new EntryState($user, $this->entry($feed, $guid));
        $state->markViewed($when);
        $this->entityManager->persist($state);
    }

    public function testReturnsOpensAtOrAfterTheBoundAndDropsEarlierOnes(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('reader@example.com', $when);
        $this->entityManager->persist($user);
        $feed = $this->subscribedFeed($user, 'https://example.com/f.xml', $when);

        $this->openedAt($user, $feed, 'before', new \DateTimeImmutable('2026-08-31T23:59:59Z'));
        $this->openedAt($user, $feed, 'on', new \DateTimeImmutable('2026-09-01T00:00:00Z'));
        $this->openedAt($user, $feed, 'after', new \DateTimeImmutable('2026-09-04T10:00:00Z'));
        $this->entityManager->flush();

        $opens = $this->repository()->viewedAtSince(
            $user->requireId(),
            new \DateTimeImmutable('2026-09-01T00:00:00Z'),
        );

        $formatted = array_map(static fn (\DateTimeImmutable $at): string => $at->format('Y-m-d H:i:s'), $opens);
        sort($formatted);
        self::assertSame(['2026-09-01 00:00:00', '2026-09-04 10:00:00'], $formatted);
    }

    public function testIgnoresOpensForFeedsTheUserNoLongerSubscribesTo(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('orphan@example.com', $when);
        $this->entityManager->persist($user);

        // A feed with no subscription: the open is orphaned and must not count.
        $unsubscribed = new Feed('https://example.com/unsub.xml');
        $this->entityManager->persist($unsubscribed);
        $this->openedAt($user, $unsubscribed, 'orphan', new \DateTimeImmutable('2026-09-03T10:00:00Z'));
        $this->entityManager->flush();

        self::assertSame(
            [],
            $this->repository()->viewedAtSince($user->requireId(), new \DateTimeImmutable('2026-09-01T00:00:00Z')),
        );
    }

    public function testIsScopedToTheUser(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $mine = new User('mine@example.com', $when);
        $other = new User('other@example.com', $when);
        $this->entityManager->persist($mine);
        $this->entityManager->persist($other);
        $feed = new Feed('https://example.com/shared.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($mine, $feed, $when));
        $this->entityManager->persist(new Subscription($other, $feed, $when));

        $theirs = new EntryState($other, $this->entry($feed, 'shared'));
        $theirs->markViewed(new \DateTimeImmutable('2026-09-03T10:00:00Z'));
        $this->entityManager->persist($theirs);
        $this->entityManager->flush();

        self::assertSame(
            [],
            $this->repository()->viewedAtSince($mine->requireId(), new \DateTimeImmutable('2026-09-01T00:00:00Z')),
        );
    }

    public function testReadCountsByFeedRanksSubscribedFeedsByOpensBusiestFirst(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('ranker@example.com', $when);
        $this->entityManager->persist($user);
        $busy = $this->subscribedFeed($user, 'https://example.com/busy.xml', $when);
        $quiet = $this->subscribedFeed($user, 'https://example.com/quiet.xml', $when);

        $this->openedAt($user, $busy, 'busy-1', $when);
        $this->openedAt($user, $busy, 'busy-2', $when);
        $this->openedAt($user, $quiet, 'quiet-1', $when);
        // An unsubscribed feed's opens must not rank.
        $orphanFeed = new Feed('https://example.com/orphan.xml');
        $this->entityManager->persist($orphanFeed);
        $this->openedAt($user, $orphanFeed, 'orphan-1', $when);
        $this->entityManager->flush();

        $ranking = $this->repository()->readCountsByFeed($user->requireId(), 5);

        self::assertSame(
            [
                ['feedId' => $busy->requireId(), 'readCount' => 2],
                ['feedId' => $quiet->requireId(), 'readCount' => 1],
            ],
            $ranking,
        );
    }

    public function testReadCountsByFeedHonoursTheLimit(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('capped@example.com', $when);
        $this->entityManager->persist($user);
        for ($feedIndex = 0; $feedIndex < 3; $feedIndex++) {
            $feed = $this->subscribedFeed($user, "https://example.com/f{$feedIndex}.xml", $when);
            $this->openedAt($user, $feed, "open-{$feedIndex}", $when);
        }
        $this->entityManager->flush();

        self::assertCount(2, $this->repository()->readCountsByFeed($user->requireId(), 2));
    }
}
