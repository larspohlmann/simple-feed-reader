<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\SubscriptionDay;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Service\SubscriptionHistory\Model\ActivityDayModel;
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

    private function subscribeToBluesky(string $handle, string $at): void
    {
        $subscription = $this->subscribeTo(\sprintf('https://bsky.app/profile/%s.bsky.social/rss', $handle), $at);
        $this->entityManager->flush();
        self::assertSame(SocialNetwork::Bluesky, $subscription->getFeed()->getNetwork());
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
}
