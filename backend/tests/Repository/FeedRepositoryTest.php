<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\FeedStatus;
use App\Repository\FeedRepository;
use App\Tests\DbTestCase;

final class FeedRepositoryTest extends DbTestCase
{
    private FeedRepository $repository;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var FeedRepository $repository */
        $repository = $this->em->getRepository(Feed::class);
        $this->repository = $repository;
        $this->now = new \DateTimeImmutable('2026-07-21 12:00:00');
    }

    private function feed(string $url, ?\DateTimeImmutable $nextFetchAt, FeedStatus $status = FeedStatus::Active): Feed
    {
        $feed = new Feed($url);
        $feed->setNextFetchAt($nextFetchAt);
        $feed->setStatus($status);
        $this->em->persist($feed);

        return $feed;
    }

    /**
     * @param list<Feed> $feeds
     *
     * @return list<int|null>
     */
    private function ids(array $feeds): array
    {
        return array_map(static fn (Feed $feed): ?int => $feed->getId(), $feeds);
    }

    public function testFindsDueFeedsOrderedNeverFetchedFirst(): void
    {
        $overdue = $this->feed('https://a.example.com/feed', $this->now->modify('-2 hours'));
        $neverFetched = $this->feed('https://b.example.com/feed', null);
        $this->feed('https://c.example.com/feed', $this->now->modify('+1 hour'));
        $this->feed('https://d.example.com/feed', $this->now->modify('-1 day'), FeedStatus::Gone);
        $this->em->flush();

        $due = $this->repository->findDue($this->now, 10);

        self::assertSame([$neverFetched->getId(), $overdue->getId()], $this->ids($due));
        self::assertSame(2, $this->repository->countDue($this->now));
    }

    public function testOrdersMostOverdueFirst(): void
    {
        $recent = $this->feed('https://a.example.com/feed', $this->now->modify('-10 minutes'));
        $ancient = $this->feed('https://b.example.com/feed', $this->now->modify('-5 days'));
        $middle = $this->feed('https://c.example.com/feed', $this->now->modify('-3 hours'));
        $this->em->flush();

        self::assertSame(
            [$ancient->getId(), $middle->getId(), $recent->getId()],
            $this->ids($this->repository->findDue($this->now, 10)),
        );
    }

    public function testLimitIsApplied(): void
    {
        $this->feed('https://a.example.com/feed', $this->now->modify('-3 hours'));
        $this->feed('https://b.example.com/feed', $this->now->modify('-2 hours'));
        $this->feed('https://c.example.com/feed', $this->now->modify('-1 hour'));
        $this->em->flush();

        self::assertCount(2, $this->repository->findDue($this->now, 2));
        self::assertSame(3, $this->repository->countDue($this->now));
    }

    public function testForceIgnoresScheduleButHonorsCooldown(): void
    {
        $fresh = $this->feed('https://a.example.com/feed', $this->now->modify('+1 hour'));
        $fresh->setLastFetchedAt($this->now->modify('-1 minute'));
        $stale = $this->feed('https://b.example.com/feed', $this->now->modify('+1 hour'));
        $stale->setLastFetchedAt($this->now->modify('-10 minutes'));
        $this->em->flush();

        $due = $this->repository->findDue(
            $this->now,
            10,
            force: true,
            cooldownCutoff: $this->now->modify('-5 minutes'),
        );

        self::assertSame([$stale->getId()], $this->ids($due));
    }

    public function testForceStillExcludesGoneFeeds(): void
    {
        $this->feed('https://gone.example.com/feed', null, FeedStatus::Gone);
        $active = $this->feed('https://ok.example.com/feed', null);
        $this->em->flush();

        $due = $this->repository->findDue($this->now, 10, force: true);

        self::assertSame([$active->getId()], $this->ids($due));
    }

    public function testUserScopeOnlyReturnsSubscribedFeeds(): void
    {
        $user = new User('reader@example.com', $this->now);
        $other = new User('other@example.com', $this->now);
        $this->em->persist($user);
        $this->em->persist($other);
        $mine = $this->feed('https://mine.example.com/feed', null);
        $theirs = $this->feed('https://other.example.com/feed', null);
        $this->em->persist(new Subscription($user, $mine, $this->now));
        $this->em->persist(new Subscription($other, $theirs, $this->now));
        $this->em->flush();

        $due = $this->repository->findDue($this->now, 10, userId: $user->getId());

        self::assertSame([$mine->getId()], $this->ids($due));
        self::assertSame(1, $this->repository->countDue($this->now, userId: $user->getId()));
    }

    public function testFeedScopeIncludesGoneFeeds(): void
    {
        $gone = $this->feed('https://gone.example.com/feed', null, FeedStatus::Gone);
        $this->em->flush();

        $due = $this->repository->findDue($this->now, 10, feedId: $gone->getId(), force: true);

        self::assertSame([$gone->getId()], $this->ids($due));
    }

    public function testNoDueFeedsReturnsEmpty(): void
    {
        $this->feed('https://a.example.com/feed', $this->now->modify('+1 hour'));
        $this->em->flush();

        self::assertSame([], $this->repository->findDue($this->now, 10));
        self::assertSame(0, $this->repository->countDue($this->now));
    }
}
