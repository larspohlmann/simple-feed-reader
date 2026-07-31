<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Admin\UserStatistics;
use App\Service\Subscription\SubscriptionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class UserStatisticsTest extends TestCase
{
    private const string NOW = '2026-07-31 12:00:00';

    private function user(?string $lastLoginAt, string $createdAt = '2026-01-01 09:00:00'): User
    {
        $user = new User('someone@example.com', new \DateTimeImmutable($createdAt));
        if (null !== $lastLoginAt) {
            $user->setLastLoginAt(new \DateTimeImmutable($lastLoginAt));
        }

        return $user;
    }

    /**
     * @param list<?string> $fetchedAt one entry per subscribed feed
     */
    private function statisticsFor(User $user, array $fetchedAt, int $tagCount): UserStatistics
    {
        $subscriptions = [];
        foreach ($fetchedAt as $stamp) {
            $feed = new Feed('https://example.test/feed');
            $feed->setLastFetchedAt(null === $stamp ? null : new \DateTimeImmutable($stamp));
            $subscriptions[] = new Subscription($user, $feed, new \DateTimeImmutable(self::NOW));
        }

        $subscriptionRepository = $this->createStub(SubscriptionRepository::class);
        $subscriptionRepository->method('findForUserWithTags')->willReturn($subscriptions);

        $tagRepository = $this->createStub(TagRepository::class);
        $tagRepository->method('findForUser')->willReturn(
            array_map(
                static fn (int $i): Tag => new Tag($user, 'tag' . $i),
                range(1, max(0, $tagCount)),
            ),
        );

        return new UserStatistics(
            $subscriptionRepository,
            $tagRepository,
            new MockClock(new \DateTimeImmutable(self::NOW)),
        );
    }

    public function testItCountsTheFootprintAgainstTheGlobalCap(): void
    {
        $user = $this->user('2026-07-30 08:00:00');
        $footprint = $this->statisticsFor($user, ['2026-07-31 06:00:00', '2026-07-30 06:00:00'], 3)
            ->forUser($user);

        self::assertSame(2, $footprint->feedsCount);
        self::assertSame(3, $footprint->tagsCount);
        self::assertSame(SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER, $footprint->feedsLimit);
    }

    public function testTheLastRefreshIsTheNewestFetchAcrossTheUsersFeeds(): void
    {
        $user = $this->user('2026-07-30 08:00:00');
        $footprint = $this->statisticsFor(
            $user,
            ['2026-07-20 06:00:00', '2026-07-29 18:30:00', '2026-07-25 06:00:00'],
            0,
        )->forUser($user);

        self::assertEquals(new \DateTimeImmutable('2026-07-29 18:30:00'), $footprint->lastRefreshAt);
    }

    public function testAnAccountWithoutFeedsHasNeverRefreshed(): void
    {
        $user = $this->user('2026-07-30 08:00:00');
        $footprint = $this->statisticsFor($user, [], 0)->forUser($user);

        self::assertNull($footprint->lastRefreshAt);
        self::assertSame(0, $footprint->feedsCount);
        self::assertSame(0, $footprint->staleFeedsCount);
    }

    public function testAFeedCountsAsStaleAfterSevenDaysAndANeverFetchedFeedAlways(): void
    {
        $user = $this->user('2026-07-30 08:00:00');
        $footprint = $this->statisticsFor($user, [
            '2026-07-31 06:00:00', // fresh
            '2026-07-25 12:00:00', // exactly 6 days — fresh
            '2026-07-24 12:00:00', // exactly 7 days — stale
            null,                  // never fetched — stale
        ], 0)->forUser($user);

        self::assertSame(2, $footprint->staleFeedsCount);
    }

    public function testAnAccountIsDormantAfterNinetyDaysWithoutALogin(): void
    {
        $recent = $this->user('2026-07-01 08:00:00');
        $stale = $this->user('2026-04-01 08:00:00');

        self::assertFalse($this->statisticsFor($recent, [], 0)->forUser($recent)->dormant);
        self::assertTrue($this->statisticsFor($stale, [], 0)->forUser($stale)->dormant);
    }

    public function testAnAccountThatNeverSignedInIsDormantOnlyOnceItIsOldEnough(): void
    {
        $young = $this->user(null, '2026-07-20 09:00:00');
        $abandoned = $this->user(null, '2026-01-01 09:00:00');

        self::assertFalse($this->statisticsFor($young, [], 0)->forUser($young)->dormant);
        self::assertTrue($this->statisticsFor($abandoned, [], 0)->forUser($abandoned)->dormant);
    }
}
