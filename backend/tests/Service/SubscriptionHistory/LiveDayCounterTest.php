<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionActivityRepository;
use App\Service\SubscriptionHistory\LiveDayCounter;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Tests\DbTestCase;

final class LiveDayCounterTest extends DbTestCase
{
    public function testBucketsArrivalsAndReadsByUtcDayAndSkipsTheSubscribeDaysArrivals(): void
    {
        $user = new User('live@example.com', new \DateTimeImmutable('2026-09-01 00:00:00'));
        $feed = new Feed('https://example.com/live.xml');
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-10-10 15:00:00'));
        $this->entityManager->persist($user);
        $this->entityManager->persist($feed);
        $this->entityManager->persist($subscription);
        $backlog = $this->entry($feed, 'backlog', '2026-10-10 15:00:05');
        $this->entry($feed, 'late-evening', '2026-10-11 23:59:59');
        $this->entry($feed, 'next-day', '2026-10-12 00:00:00');
        $state = new EntryState($user, $backlog);
        $state->markViewed(new \DateTimeImmutable('2026-10-10 16:00:00'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();
        $tracked = new TrackedSubscriptionModel($subscription->requireId(), '2026-10-10', false);

        $counts = new LiveDayCounter(new SubscriptionActivityRepository($this->entityManager))
            ->since([$tracked], '2026-10-10');

        $subscriptionId = $subscription->requireId();
        self::assertSame(0, $counts->of($subscriptionId, '2026-10-10')->arrived);
        self::assertSame(1, $counts->of($subscriptionId, '2026-10-10')->read);
        self::assertSame(1, $counts->of($subscriptionId, '2026-10-11')->arrived);
        self::assertSame(1, $counts->of($subscriptionId, '2026-10-12')->arrived);
    }

    public function testNoSubscriptionsCountsNothing(): void
    {
        $counts = new LiveDayCounter(new SubscriptionActivityRepository($this->entityManager))->since([], '2026-10-10');

        self::assertFalse($counts->has(1, '2026-10-10'));
    }

    private function entry(Feed $feed, string $guid, string $createdAt): Entry
    {
        $created = new \DateTimeImmutable($createdAt);
        $entry = new Entry($feed, $guid, null, $guid, $created, $created);
        $this->entityManager->persist($entry);

        return $entry;
    }
}
