<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory;

use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Entity\SubscriptionDay;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Service\SubscriptionHistory\Support\UtcDay;
use App\Service\SubscriptionHistory\SubscriptionHistoryRecorder;
use App\Tests\DbTestCase;
use App\Tests\Support\SubscriptionHistoryFixtures;

/** Now is 2026-10-25 12:00 UTC; default retention, so plain feeds reach back 90 days and social ones to 2026-10-19. */
final class SubscriptionHistoryRecorderTest extends DbTestCase
{
    use SubscriptionHistoryFixtures;

    private function recorder(): SubscriptionHistoryRecorder
    {
        return $this->historyWiring('2026-10-25 12:00:00')->recorder();
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
        $subscription = $this->subscriptionOfNewUser('fresh@example.com', '2026-10-22 08:00:00');
        $backlog = $this->arrival($subscription->getFeed(), 'backlog', '2026-10-22 08:00:01');
        $this->arrival($subscription->getFeed(), 'first-real', '2026-10-23 10:00:00');
        $state = new EntryState($subscription->getUser(), $backlog);
        $state->markViewed(new \DateTimeImmutable('2026-10-23 11:00:00'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        $written = $this->recorder()->recordCompletedDays();

        self::assertSame(3, $written);
        self::assertSame(['2026-10-22', '2026-10-23', '2026-10-24'], $this->recordedDays($subscription));
        $stored = $this->storedDays()->since([$subscription->requireId()], '2026-10-22');
        self::assertSame(0, $stored->of($subscription->requireId(), '2026-10-22')->arrived);
        self::assertSame(1, $stored->of($subscription->requireId(), '2026-10-23')->arrived);
        self::assertSame(0, $stored->of($subscription->requireId(), '2026-10-22')->read);
        self::assertSame(1, $stored->of($subscription->requireId(), '2026-10-23')->read);
    }

    public function testBackfillsAtMostTheThirtyDayWindowForAnOldPlainFeed(): void
    {
        $subscription = $this->subscriptionOfNewUser('old@example.com', '2026-01-01 00:00:00');
        $this->entityManager->flush();

        $this->recorder()->recordCompletedDays();

        $days = $this->recordedDays($subscription);
        self::assertCount(29, $days);
        self::assertSame('2026-09-26', $days[0]);
        self::assertSame('2026-10-24', $days[28]);
    }

    public function testASocialFeedIsRecordedOnlyBackToItsRetentionHorizon(): void
    {
        $subscription = $this->subscriptionOfNewUser(
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
        $subscription = $this->subscriptionOfNewUser('stored@example.com', '2026-10-20 00:00:00');
        $this->entityManager->persist(new SubscriptionDay($subscription, UtcDay::start('2026-10-23'), 42, 7));
        $this->arrival($subscription->getFeed(), 'live', '2026-10-23 10:00:00');
        $this->entityManager->flush();

        $this->recorder()->recordCompletedDays();
        $secondRun = $this->recorder()->recordCompletedDays();

        self::assertSame(0, $secondRun);
        $subscriptionId = $subscription->requireId();
        $stored = $this->storedDays()->since([$subscriptionId], '2026-10-23');
        self::assertSame(42, $stored->of($subscriptionId, '2026-10-23')->arrived);
    }

    public function testOnlyTheDaysNotYetStoredAreWrittenAndAFullyStoredSubscriptionKeepsItsRows(): void
    {
        $complete = $this->subscriptionOfNewUser('complete@example.com', '2026-10-23 00:00:00');
        $this->entityManager->persist(new SubscriptionDay($complete, UtcDay::start('2026-10-23'), 5, 0));
        $this->entityManager->persist(new SubscriptionDay($complete, UtcDay::start('2026-10-24'), 6, 0));
        $fresh = $this->subscribe(
            new User('fresh-subscriber@example.com', new \DateTimeImmutable('2026-01-01 00:00:00')),
            $complete->getFeed(),
            '2026-10-23 00:00:00',
        );
        $this->arrival($complete->getFeed(), 'shared', '2026-10-24 10:00:00');
        $this->entityManager->flush();

        $written = $this->recorder()->recordCompletedDays();

        self::assertSame(2, $written);
        self::assertSame(['2026-10-23', '2026-10-24'], $this->recordedDays($fresh));
        $stored = $this->storedDays()->since([$complete->requireId()], '2026-10-23');
        self::assertSame(6, $stored->of($complete->requireId(), '2026-10-24')->arrived);
    }

    public function testAFirstRunBackfillIsWrittenInFlushedChunksAndLeavesNoDayManaged(): void
    {
        for ($index = 0; $index < 18; ++$index) {
            $this->subscriptionOfNewUser('chunk-' . $index . '@example.com', '2026-01-01 00:00:00');
        }
        $this->entityManager->flush();

        $written = $this->recorder()->recordCompletedDays();

        self::assertSame(18 * 29, $written);
        $this->assertNoneManaged(SubscriptionDay::class);
        self::assertCount(18 * 29, $this->storedDays()->findAll());
    }
}
