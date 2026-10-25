<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\EntryStateRepository;
use App\Tests\DbTestCase;

/** The per-feed read ranking of the reading-activity chart: still-subscribed plain feeds only, busiest first. */
final class ReadCountsByFeedTest extends DbTestCase
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

    public function testReadCountsByFeedLeavesSocialFeedsOut(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('social-reader@example.com', $when);
        $this->entityManager->persist($user);
        $bluesky = $this->subscribedFeed($user, 'https://bsky.app/profile/busy.bsky.social/rss', $when);
        $plain = $this->subscribedFeed($user, 'https://example.com/plain.xml', $when);
        $this->openedAt($user, $bluesky, 'bluesky-1', $when);
        $this->openedAt($user, $bluesky, 'bluesky-2', $when);
        $this->openedAt($user, $plain, 'plain-1', $when);
        $this->entityManager->flush();
        self::assertSame(SocialNetwork::Bluesky, $bluesky->getNetwork());

        self::assertSame(
            [['feedId' => $plain->requireId(), 'readCount' => 1]],
            $this->repository()->readCountsByFeed($user->requireId(), 5),
        );
    }
}
