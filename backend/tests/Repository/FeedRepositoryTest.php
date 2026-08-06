<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\FeedStatus;
use App\Repository\DueFeedCriteria;
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

        $due = $this->repository->findDue(new DueFeedCriteria($this->now), 10);

        self::assertSame([$neverFetched->getId(), $overdue->getId()], $this->ids($due));
        self::assertSame(2, $this->repository->countDue(new DueFeedCriteria($this->now)));
    }

    /**
     * The refresh sweep excludes the feeds it already took on, so a feed whose
     * outcome writes no fetch time — a 429 (#290) — still leaves `remaining`
     * and the client's poll loop terminates (#302).
     */
    public function testExcludedFeedsAreNeitherReturnedNorCounted(): void
    {
        $handled = $this->feed('https://a.example.com/feed', $this->now->modify('-2 hours'));
        $untouched = $this->feed('https://b.example.com/feed', $this->now->modify('-1 hour'));
        $this->em->flush();

        $criteria = (new DueFeedCriteria($this->now))->excluding([(int) $handled->getId()]);

        self::assertSame([$untouched->getId()], $this->ids($this->repository->findDue($criteria, 10)));
        self::assertSame(1, $this->repository->countDue($criteria));
        // Without the exclusion both are due, so the filter is what moved the count.
        self::assertSame(2, $this->repository->countDue(new DueFeedCriteria($this->now)));
    }

    /**
     * `excluding()` replaces only the exclusion list. A scope it dropped on the
     * way would silently widen a per-user sweep into every user's feeds.
     */
    public function testExcludingKeepsEveryOtherScope(): void
    {
        $original = new DueFeedCriteria(
            $this->now,
            userId: 7,
            feedId: 8,
            tagId: 9,
            force: true,
            cooldownCutoff: $this->now->modify('-5 minutes'),
        );

        $narrowed = $original->excluding([42]);

        self::assertSame([42], $narrowed->excludedFeedIds);
        self::assertSame($original->now, $narrowed->now);
        self::assertSame(7, $narrowed->userId);
        self::assertSame(8, $narrowed->feedId);
        self::assertSame(9, $narrowed->tagId);
        self::assertTrue($narrowed->force);
        self::assertEquals($original->cooldownCutoff, $narrowed->cooldownCutoff);
        self::assertSame([], $original->excludedFeedIds);
    }

    public function testOrdersMostOverdueFirst(): void
    {
        $recent = $this->feed('https://a.example.com/feed', $this->now->modify('-10 minutes'));
        $ancient = $this->feed('https://b.example.com/feed', $this->now->modify('-5 days'));
        $middle = $this->feed('https://c.example.com/feed', $this->now->modify('-3 hours'));
        $this->em->flush();

        self::assertSame(
            [$ancient->getId(), $middle->getId(), $recent->getId()],
            $this->ids($this->repository->findDue(new DueFeedCriteria($this->now), 10)),
        );
    }

    public function testLimitIsApplied(): void
    {
        $this->feed('https://a.example.com/feed', $this->now->modify('-3 hours'));
        $this->feed('https://b.example.com/feed', $this->now->modify('-2 hours'));
        $this->feed('https://c.example.com/feed', $this->now->modify('-1 hour'));
        $this->em->flush();

        self::assertCount(2, $this->repository->findDue(new DueFeedCriteria($this->now), 2));
        self::assertSame(3, $this->repository->countDue(new DueFeedCriteria($this->now)));
    }

    public function testForceIgnoresScheduleButHonorsCooldown(): void
    {
        $fresh = $this->feed('https://a.example.com/feed', $this->now->modify('+1 hour'));
        $fresh->setLastFetchedAt($this->now->modify('-1 minute'));
        $stale = $this->feed('https://b.example.com/feed', $this->now->modify('+1 hour'));
        $stale->setLastFetchedAt($this->now->modify('-10 minutes'));
        $this->em->flush();

        $due = $this->repository->findDue(
            new DueFeedCriteria($this->now, force: true, cooldownCutoff: $this->now->modify('-5 minutes')),
            10,
        );

        self::assertSame([$stale->getId()], $this->ids($due));
    }

    public function testForceTreatsAFutureLastFetchedAtAsEligible(): void
    {
        // A worker on the wrong timezone stamped lastFetchedAt in the future in
        // #151. Read by a correct clock, such a value must not count as "just
        // fetched" and freeze the feed out of every refresh — a future fetch
        // time is impossible, so the feed is due.
        $future = $this->feed('https://a.example.com/feed', $this->now->modify('+1 hour'));
        $future->setLastFetchedAt($this->now->modify('+59 minutes'));
        $this->em->flush();

        $due = $this->repository->findDue(
            new DueFeedCriteria($this->now, force: true, cooldownCutoff: $this->now->modify('-5 minutes')),
            10,
        );

        self::assertSame([$future->getId()], $this->ids($due));
        self::assertSame(
            1,
            $this->repository->countDue(new DueFeedCriteria(
                $this->now,
                force: true,
                cooldownCutoff: $this->now->modify('-5 minutes'),
            )),
        );
    }

    public function testForceStillExcludesGoneFeeds(): void
    {
        $this->feed('https://gone.example.com/feed', null, FeedStatus::Gone);
        $active = $this->feed('https://ok.example.com/feed', null);
        $this->em->flush();

        $due = $this->repository->findDue(new DueFeedCriteria($this->now, force: true), 10);

        self::assertSame([$active->getId()], $this->ids($due));
    }

    /**
     * Force without a cooldown means exactly that: no fetch-time test at all.
     * A feed fetched one minute ago is still returned — only a cutoff may hold
     * one back, and there is none here.
     */
    public function testForceWithoutACooldownIgnoresTheFetchTime(): void
    {
        $justFetched = $this->feed('https://a.example.com/feed', $this->now->modify('+1 hour'));
        $justFetched->setLastFetchedAt($this->now->modify('-1 minute'));
        $this->em->flush();

        $due = $this->repository->findDue(new DueFeedCriteria($this->now, force: true), 10);

        self::assertSame([$justFetched->getId()], $this->ids($due));
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

        $due = $this->repository->findDue(new DueFeedCriteria($this->now, userId: $user->getId()), 10);

        self::assertSame([$mine->getId()], $this->ids($due));
        self::assertSame(1, $this->repository->countDue(new DueFeedCriteria($this->now, userId: $user->getId())));
    }

    public function testFeedScopeIncludesGoneFeeds(): void
    {
        $gone = $this->feed('https://gone.example.com/feed', null, FeedStatus::Gone);
        // A second due feed, so the assertion below fails if the id filter is
        // dropped rather than passing on a one-row fixture.
        $this->feed('https://ok.example.com/feed', null);
        $this->em->flush();

        $due = $this->repository->findDue(new DueFeedCriteria($this->now, feedId: $gone->getId(), force: true), 10);

        self::assertSame([$gone->getId()], $this->ids($due));
    }

    public function testNoDueFeedsReturnsEmpty(): void
    {
        $this->feed('https://a.example.com/feed', $this->now->modify('+1 hour'));
        $this->em->flush();

        self::assertSame([], $this->repository->findDue(new DueFeedCriteria($this->now), 10));
        self::assertSame(0, $this->repository->countDue(new DueFeedCriteria($this->now)));
    }
}
