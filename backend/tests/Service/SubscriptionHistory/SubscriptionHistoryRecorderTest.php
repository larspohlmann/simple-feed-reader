<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\SubscriptionDay;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SubscriptionActivityRepository;
use App\Repository\SubscriptionDayRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Retention\RetentionSettings;
use App\Service\SubscriptionHistory\LiveDayCounter;
use App\Service\SubscriptionHistory\RetentionHorizon;
use App\Service\SubscriptionHistory\Support\UtcDay;
use App\Service\SubscriptionHistory\SubscriptionHistoryRecorder;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

/** Now is 2026-10-25 12:00 UTC; default retention, so plain feeds reach back 90 days and social ones to 2026-10-19. */
final class SubscriptionHistoryRecorderTest extends DbTestCase
{
    private function recorder(): SubscriptionHistoryRecorder
    {
        $settings = self::getContainer()->get(RetentionSettings::class);
        self::assertInstanceOf(RetentionSettings::class, $settings);
        $activity = new SubscriptionActivityRepository($this->entityManager);

        return new SubscriptionHistoryRecorder(
            $activity,
            $this->storedDays(),
            new LiveDayCounter($activity),
            new RetentionHorizon($settings),
            new NaiveUtcClock(new MockClock('2026-10-25 12:00:00', 'UTC')),
            $this->entityManager,
        );
    }

    private function storedDays(): SubscriptionDayRepository
    {
        $repository = $this->entityManager->getRepository(SubscriptionDay::class);
        self::assertInstanceOf(SubscriptionDayRepository::class, $repository);

        return $repository;
    }

    private function subscription(string $email, string $subscribedAt, string $feedUrl = ''): Subscription
    {
        $user = new User($email, new \DateTimeImmutable('2026-01-01 00:00:00'));
        $feed = new Feed($feedUrl !== '' ? $feedUrl : 'https://example.com/' . $email . '.xml');
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable($subscribedAt));
        $this->entityManager->persist($user);
        $this->entityManager->persist($feed);
        $this->entityManager->persist($subscription);

        return $subscription;
    }

    private function arrival(Subscription $subscription, string $guid, string $createdAt): void
    {
        $created = new \DateTimeImmutable($createdAt);
        $this->entityManager->persist(new Entry($subscription->getFeed(), $guid, null, $guid, $created, $created));
    }

    /** @return list<string> */
    private function recordedDays(Subscription $subscription): array
    {
        $days = array_map(
            static fn (SubscriptionDay $row): string => UtcDay::of($row->getDay()),
            $this->storedDays()->findBy(['subscription' => $subscription]),
        );
        sort($days);

        return $days;
    }

    public function testRecordsEveryCompletedDayFromTheSubscribeDayWithTheSubscribeDayAtZeroArrivals(): void
    {
        $subscription = $this->subscription('fresh@example.com', '2026-10-22 08:00:00');
        $this->arrival($subscription, 'backlog', '2026-10-22 08:00:01');
        $this->arrival($subscription, 'first-real', '2026-10-23 10:00:00');
        $this->entityManager->flush();

        $written = $this->recorder()->recordCompletedDays();

        self::assertSame(3, $written);
        self::assertSame(['2026-10-22', '2026-10-23', '2026-10-24'], $this->recordedDays($subscription));
        $stored = $this->storedDays()->since([$subscription->requireId()], '2026-10-22');
        self::assertSame(0, $stored->of($subscription->requireId(), '2026-10-22')->arrived);
        self::assertSame(1, $stored->of($subscription->requireId(), '2026-10-23')->arrived);
    }

    public function testBackfillsAtMostTheThirtyDayWindowForAnOldPlainFeed(): void
    {
        $subscription = $this->subscription('old@example.com', '2026-01-01 00:00:00');
        $this->entityManager->flush();

        $this->recorder()->recordCompletedDays();

        $days = $this->recordedDays($subscription);
        self::assertCount(29, $days);
        self::assertSame('2026-09-26', $days[0]);
        self::assertSame('2026-10-24', $days[28]);
    }

    public function testASocialFeedIsRecordedOnlyBackToItsRetentionHorizon(): void
    {
        $subscription = $this->subscription(
            'timeline@example.com',
            '2026-01-01 00:00:00',
            'https://bsky.app/profile/timeline.bsky.social/rss',
        );
        $this->entityManager->flush();
        self::assertSame(SocialNetwork::Bluesky, $subscription->getFeed()->getNetwork());

        $this->recorder()->recordCompletedDays();

        self::assertSame(
            ['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24'],
            $this->recordedDays($subscription),
        );
    }

    public function testAStoredDayIsNeverRewritten(): void
    {
        $subscription = $this->subscription('stored@example.com', '2026-10-20 00:00:00');
        $this->entityManager->persist(new SubscriptionDay($subscription, UtcDay::start('2026-10-23'), 42, 7));
        $this->arrival($subscription, 'live', '2026-10-23 10:00:00');
        $this->entityManager->flush();

        $this->recorder()->recordCompletedDays();
        $secondRun = $this->recorder()->recordCompletedDays();

        self::assertSame(0, $secondRun);
        $subscriptionId = $subscription->requireId();
        $stored = $this->storedDays()->since([$subscriptionId], '2026-10-23');
        self::assertSame(42, $stored->of($subscriptionId, '2026-10-23')->arrived);
    }
}
