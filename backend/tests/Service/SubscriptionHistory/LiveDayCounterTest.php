<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory;

use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\User;
use App\Repository\SubscriptionActivityRepository;
use App\Service\SubscriptionHistory\LiveDayCounter;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Tests\DbTestCase;
use App\Tests\Support\SubscriptionHistoryFixtures;

final class LiveDayCounterTest extends DbTestCase
{
    use SubscriptionHistoryFixtures;

    private function counter(): LiveDayCounter
    {
        return new LiveDayCounter(new SubscriptionActivityRepository($this->entityManager));
    }

    public function testBucketsArrivalsAndReadsByUtcDayAndSkipsTheSubscribeDaysArrivals(): void
    {
        $subscription = $this->subscriptionOfNewUser('live@example.com', '2026-10-10 15:00:00');
        $feed = $subscription->getFeed();
        $backlog = $this->arrival($feed, 'backlog', '2026-10-10 15:00:05');
        $this->arrival($feed, 'late-evening', '2026-10-11 23:59:59');
        $this->arrival($feed, 'next-day', '2026-10-12 00:00:00');
        $state = new EntryState($subscription->getUser(), $backlog);
        $state->markViewed(new \DateTimeImmutable('2026-10-10 16:00:00'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();
        $tracked = new TrackedSubscriptionModel($subscription->requireId(), $feed->requireId(), '2026-10-10', false);

        $counts = $this->counter()->since([$tracked], '2026-10-10');

        $subscriptionId = $subscription->requireId();
        self::assertSame(0, $counts->of($subscriptionId, '2026-10-10')->arrived);
        self::assertSame(1, $counts->of($subscriptionId, '2026-10-10')->read);
        self::assertSame(1, $counts->of($subscriptionId, '2026-10-11')->arrived);
        self::assertSame(1, $counts->of($subscriptionId, '2026-10-12')->arrived);
    }

    public function testEverySubscriberOfAFeedGetsItsArrivalsAfterTheirOwnSubscribeDay(): void
    {
        $feed = new Feed('https://example.com/shared.xml');
        $early = $this->subscribe($this->user('early@example.com'), $feed, '2026-10-09 08:00:00');
        $late = $this->subscribe($this->user('late@example.com'), $feed, '2026-10-11 08:00:00');
        $this->arrival($feed, 'first', '2026-10-11 09:00:00');
        $this->arrival($feed, 'second', '2026-10-11 10:00:00');
        $this->arrival($feed, 'third', '2026-10-12 09:00:00');
        $this->entityManager->flush();

        $counts = $this->counter()->since([
            new TrackedSubscriptionModel($early->requireId(), $feed->requireId(), '2026-10-09', false),
            new TrackedSubscriptionModel($late->requireId(), $feed->requireId(), '2026-10-11', false),
        ], '2026-10-10');

        self::assertSame(2, $counts->of($early->requireId(), '2026-10-11')->arrived);
        self::assertSame(1, $counts->of($early->requireId(), '2026-10-12')->arrived);
        self::assertFalse($counts->has($late->requireId(), '2026-10-11'));
        self::assertSame(1, $counts->of($late->requireId(), '2026-10-12')->arrived);
    }

    public function testNoSubscriptionsCountsNothing(): void
    {
        self::assertFalse($this->counter()->since([], '2026-10-10')->has(1, '2026-10-10'));
    }

    private function user(string $email): User
    {
        return new User($email, new \DateTimeImmutable('2026-09-01 00:00:00'));
    }
}
