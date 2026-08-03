<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\OrphanedFeedReclaimer;
use App\Tests\DbTestCase;

final class OrphanedFeedReclaimerTest extends DbTestCase
{
    private const string NOW = '2026-07-01 10:00:00';

    private OrphanedFeedReclaimer $reclaimer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reclaimer = new OrphanedFeedReclaimer($this->em);
    }

    public function testReclaimDeletesAFeedNobodySubscribesTo(): void
    {
        $feed = $this->feed('https://orphan.example.com/rss');

        self::assertTrue($this->reclaimer->reclaim((int) $feed->getId()));

        $this->em->clear();
        self::assertNull($this->em->getRepository(Feed::class)->find($feed->getId()));
    }

    public function testReclaimKeepsAFeedThatStillHasASubscriber(): void
    {
        $feed = $this->feed('https://kept.example.com/rss');
        $this->subscribe($this->user('keeper@example.com'), $feed);
        $feedId = (int) $feed->getId();

        self::assertFalse($this->reclaimer->reclaim($feedId));

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Feed::class)->find($feedId));
    }

    public function testReclaimTakesTheFeedsEntriesWithIt(): void
    {
        $feed = $this->feed('https://withentries.example.com/rss');
        $this->em->persist(new Entry(
            $feed,
            'guid-1',
            'https://withentries.example.com/1',
            'Title',
            new \DateTimeImmutable(self::NOW),
        ));
        $this->em->flush();

        $this->reclaimer->reclaim((int) $feed->getId());

        $this->em->clear();
        self::assertSame(0, (int) $this->em->createQuery(
            'SELECT COUNT(e.id) FROM App\Entity\Entry e',
        )->getSingleScalarResult());
    }

    public function testReclaimAllDeletesOnlyTheOrphans(): void
    {
        $orphanOne = $this->feed('https://orphan-1.example.com/rss');
        $orphanTwo = $this->feed('https://orphan-2.example.com/rss');
        $kept = $this->feed('https://kept-2.example.com/rss');
        $this->subscribe($this->user('keeper-2@example.com'), $kept);

        self::assertSame(2, $this->reclaimer->reclaimAll());

        $this->em->clear();
        $feeds = $this->em->getRepository(Feed::class);
        self::assertNull($feeds->find($orphanOne->getId()));
        self::assertNull($feeds->find($orphanTwo->getId()));
        self::assertNotNull($feeds->find($kept->getId()));
    }

    public function testReclaimAllOnACleanDatabaseDeletesNothing(): void
    {
        self::assertSame(0, $this->reclaimer->reclaimAll());
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);
        $this->em->flush();

        return $feed;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable(self::NOW));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function subscribe(User $user, Feed $feed): void
    {
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable(self::NOW)));
        $this->em->flush();
    }
}
