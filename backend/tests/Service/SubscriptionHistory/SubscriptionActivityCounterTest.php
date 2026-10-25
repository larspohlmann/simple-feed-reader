<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory;

use App\Entity\Feed;
use App\Entity\RetentionWindows;
use App\Entity\Subscription;
use App\Entity\SubscriptionDay;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Service\SubscriptionHistory\Model\ActivityDayModel;
use App\Service\SubscriptionHistory\Model\FeedArrivalRateModel;
use App\Service\SubscriptionHistory\Support\UtcDay;
use App\Tests\DbTestCase;
use App\Tests\Support\SubscriptionHistoryFixtures;

/** Now is 2026-10-25 12:00 UTC: the window runs 2026-09-26 … 2026-10-25, social feeds are live back to 2026-10-19. */
final class SubscriptionActivityCounterTest extends DbTestCase
{
    use SubscriptionHistoryFixtures;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('counter@example.com', new \DateTimeImmutable('2026-01-01 00:00:00'));
        $this->entityManager->persist($this->user);
    }

    private function subscribeTo(string $url, string $at): Subscription
    {
        return $this->subscribe($this->user, new Feed($url), $at);
    }

    private function subscribeToBluesky(string $handle, string $at): Subscription
    {
        $subscription = $this->subscribeTo(\sprintf('https://bsky.app/profile/%s.bsky.social/rss', $handle), $at);
        $this->entityManager->flush();
        self::assertSame(SocialNetwork::Bluesky, $subscription->getFeed()->getNetwork());

        return $subscription;
    }

    private function arrivalsOn(Subscription $subscription, string $day, int $count): void
    {
        for ($index = 0; $index < $count; ++$index) {
            $guid = \sprintf('%d-%s-%d', spl_object_id($subscription), $day, $index);
            $this->arrival($subscription->getFeed(), $guid, $day . ' 10:00:00');
        }
    }

    /** @return array<int, float> subscription id => daily average, busiest first */
    private function topFeedsByArrivals(): array
    {
        $this->entityManager->flush();
        $activity = $this->historyWiring('2026-10-25 12:00:00')->counter()->lastDays($this->user);
        $rates = [];
        foreach ($activity->topFeedsByArrivals as $rate) {
            self::assertInstanceOf(FeedArrivalRateModel::class, $rate);
            $rates[$rate->subscriptionId] = $rate->dailyAverage;
        }

        return $rates;
    }

    /** @return array<string, ActivityDayModel> */
    private function byDate(): array
    {
        $days = [];
        foreach ($this->historyWiring('2026-10-25 12:00:00')->counter()->lastDays($this->user)->days as $day) {
            $days[$day->date] = $day;
        }

        return $days;
    }

    public function testThirtyDaysEndingTodayAndTodayIsLive(): void
    {
        $plain = $this->subscribeTo('https://example.com/a.xml', '2026-09-01 00:00:00');
        $this->arrival($plain->getFeed(), 'today', '2026-10-25 09:00:00');
        $this->entityManager->flush();

        $days = $this->byDate();

        self::assertCount(30, $days);
        self::assertSame('2026-09-26', array_key_first($days));
        self::assertSame(1, $days['2026-10-25']->arrived());
    }

    public function testStoredRowsWinAndLiveFillsTheRest(): void
    {
        $plain = $this->subscribeTo('https://example.com/b.xml', '2026-09-01 00:00:00');
        $this->entityManager->persist(new SubscriptionDay($plain, UtcDay::start('2026-10-20'), 40, 3));
        $this->arrival($plain->getFeed(), 'pruned-later', '2026-10-20 10:00:00');
        $this->arrival($plain->getFeed(), 'live', '2026-10-21 10:00:00');
        $this->entityManager->flush();

        $days = $this->byDate();

        self::assertSame(40, $days['2026-10-20']->arrived());
        self::assertSame(3, $days['2026-10-20']->read());
        self::assertSame(1, $days['2026-10-21']->arrived());
    }

    public function testASocialSubscriptionMakesOlderUnstoredDaysUnknownForTheWholeAccount(): void
    {
        $plain = $this->subscribeTo('https://example.com/c.xml', '2026-09-01 00:00:00');
        $this->subscribeToBluesky('home', '2026-09-01 00:00:00');
        $this->arrival($plain->getFeed(), 'hidden', '2026-10-10 10:00:00');
        $this->entityManager->flush();

        $days = $this->byDate();

        self::assertNull($days['2026-10-10']->arrived());
        self::assertNull($days['2026-10-18']->read());
        self::assertSame(0, $days['2026-10-19']->arrived());
    }

    public function testASubscriptionCreatedMidWindowDoesNotMakeEarlierDaysUnknown(): void
    {
        $this->subscribeTo('https://example.com/d.xml', '2026-09-01 00:00:00');
        $this->subscribeToBluesky('late', '2026-10-22 00:00:00');

        $days = $this->byDate();

        self::assertSame(0, $days['2026-10-05']->arrived());
    }

    public function testAnAccountWithoutSubscriptionsHasThirtyKnownZeroDays(): void
    {
        $this->entityManager->flush();

        $days = $this->byDate();

        self::assertCount(30, $days);
        $arrivals = array_map(static fn (ActivityDayModel $day): ?int => $day->arrived(), $days);
        self::assertSame([0], array_values(array_unique($arrivals)));
    }

    public function testRanksSubscriptionsByTheirMeanArrivalsOverTheSevenCompletedDays(): void
    {
        $steady = $this->subscribeTo('https://example.com/steady.xml', '2026-09-01 00:00:00');
        $trickle = $this->subscribeTo('https://example.com/trickle.xml', '2026-09-01 00:00:00');
        $busy = $this->subscribeTo('https://example.com/busy.xml', '2026-09-01 00:00:00');
        $silent = $this->subscribeTo('https://example.com/silent.xml', '2026-09-01 00:00:00');
        $this->arrivalsOn($steady, '2026-10-18', 7);
        $this->arrivalsOn($steady, '2026-10-24', 7);
        $this->arrivalsOn($trickle, '2026-10-20', 7);
        $this->arrivalsOn($trickle, '2026-10-25', 100);
        $this->arrivalsOn($busy, '2026-10-22', 21);
        $this->arrivalsOn($silent, '2026-10-17', 50);

        $rates = $this->topFeedsByArrivals();

        self::assertSame(
            [$busy->requireId() => 3.0, $steady->requireId() => 2.0, $trickle->requireId() => 1.0],
            $rates,
        );
    }

    public function testASubscriptionCreatedThisWeekAveragesOverTheDaysSinceItsCreation(): void
    {
        $recent = $this->subscribeTo('https://example.com/recent.xml', '2026-10-22 08:00:00');
        $this->arrivalsOn($recent, '2026-10-22', 30);
        $this->arrivalsOn($recent, '2026-10-23', 6);

        $rates = $this->topFeedsByArrivals();

        self::assertSame([$recent->requireId() => 2.0], $rates);
    }

    public function testKeepsTheFiveBusiestAndBreaksTiesByTheOlderSubscription(): void
    {
        $subscriptions = [];
        foreach ([1, 2, 3, 3, 5, 6] as $index => $weeklyArrivals) {
            $subscriptions[$index] = $this->subscribeTo("https://example.com/ranked-$index.xml", '2026-09-01 00:00:00');
            $this->arrivalsOn($subscriptions[$index], '2026-10-21', $weeklyArrivals * 7);
        }

        $rates = $this->topFeedsByArrivals();

        self::assertSame(
            [
                $subscriptions[5]->requireId(),
                $subscriptions[4]->requireId(),
                $subscriptions[2]->requireId(),
                $subscriptions[3]->requireId(),
                $subscriptions[1]->requireId(),
            ],
            array_keys($rates),
        );
    }

    public function testLeavesSocialSubscriptionsOutOfTheRanking(): void
    {
        $bluesky = $this->subscribeToBluesky('busy', '2026-09-01 00:00:00');
        $plain = $this->subscribeTo('https://example.com/quiet.xml', '2026-09-01 00:00:00');
        $this->arrivalsOn($bluesky, '2026-10-24', 70);
        $this->arrivalsOn($plain, '2026-10-24', 7);

        $rates = $this->topFeedsByArrivals();

        self::assertSame([$plain->requireId() => 1.0], $rates);
    }

    public function testAveragesOverTheKnownDaysOnlyOnceRetentionHasPrunedTheOlderOnes(): void
    {
        $this->retentionSettings()->update(new RetentionWindows(entryDays: 5, socialDays: 5));
        $pruned = $this->subscribeTo('https://example.com/pruned.xml', '2026-09-01 00:00:00');
        $this->entityManager->persist(new SubscriptionDay($pruned, UtcDay::start('2026-10-18'), 12, 0));
        $this->arrivalsOn($pruned, '2026-10-19', 40);
        $this->arrivalsOn($pruned, '2026-10-22', 8);

        $rates = $this->topFeedsByArrivals();

        self::assertSame([$pruned->requireId() => 4.0], $rates);
    }
}
