<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory\Pass;

use App\Service\SubscriptionHistory\Model\DayCountModel;
use App\Service\SubscriptionHistory\Model\DayCountsModel;
use App\Service\SubscriptionHistory\Model\RetentionHorizonModel;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Service\SubscriptionHistory\Pass\DaySources;
use PHPUnit\Framework\TestCase;

final class DaySourcesTest extends TestCase
{
    private const string OLDEST_FEED_DAY = '2026-07-01';
    private const string OLDEST_SOCIAL_DAY = '2026-10-19';

    private function sources(DayCountsModel $stored): DaySources
    {
        return new DaySources($stored, new RetentionHorizonModel(self::OLDEST_FEED_DAY, self::OLDEST_SOCIAL_DAY));
    }

    public function testASocialDayBeforeItsHorizonIsUnknownUnlessStored(): void
    {
        $social = new TrackedSubscriptionModel(1, 1, '2026-09-01', true);
        $stored = new DayCountsModel([1 => ['2026-10-17' => new DayCountModel(5, 1)]]);

        self::assertTrue($this->sources($stored)->isKnown($social, '2026-10-17'));
        self::assertFalse($this->sources($stored)->isKnown($social, '2026-10-18'));
        self::assertTrue($this->sources($stored)->isKnown($social, self::OLDEST_SOCIAL_DAY));
    }

    public function testAPlainFeedUsesTheLongerHorizon(): void
    {
        $plain = new TrackedSubscriptionModel(2, 2, '2026-09-01', false);

        self::assertTrue($this->sources(new DayCountsModel([]))->isKnown($plain, '2026-10-01'));
    }

    public function testDaysBeforeTheSubscriptionDoNotContribute(): void
    {
        $late = new TrackedSubscriptionModel(3, 3, '2026-10-20', false);

        self::assertFalse($this->sources(new DayCountsModel([]))->contributes($late, '2026-10-19'));
        self::assertTrue($this->sources(new DayCountsModel([]))->contributes($late, '2026-10-20'));
        self::assertFalse($this->sources(new DayCountsModel([]))->needsLive($late, '2026-10-19'));
    }

    public function testAStoredDayNeedsNoLiveCountAndWins(): void
    {
        $plain = new TrackedSubscriptionModel(4, 4, '2026-09-01', false);
        $stored = new DayCountsModel([4 => ['2026-10-10' => new DayCountModel(7, 2)]]);
        $live = new DayCountsModel([4 => ['2026-10-10' => new DayCountModel(1, 1)]]);

        self::assertFalse($this->sources($stored)->needsLive($plain, '2026-10-10'));
        self::assertSame(7, $this->sources($stored)->countFor($plain, '2026-10-10', $live)->arrived);
        $liveOnly = new DayCountsModel([4 => ['2026-10-11' => new DayCountModel(1, 0)]]);
        self::assertSame(1, $this->sources($stored)->countFor($plain, '2026-10-11', $liveOnly)->arrived);
    }

    public function testOldestLiveDayIsTheEarliestDayAnySubscriptionMustCountLive(): void
    {
        $plain = new TrackedSubscriptionModel(5, 5, '2026-10-12', false);
        $social = new TrackedSubscriptionModel(6, 6, '2026-09-01', true);
        $stored = new DayCountsModel([5 => ['2026-10-12' => new DayCountModel(0, 0)]]);
        $dates = ['2026-10-11', '2026-10-12', '2026-10-13', '2026-10-19', '2026-10-20'];

        self::assertSame('2026-10-13', $this->sources($stored)->oldestLiveDay([$plain, $social], $dates));
        self::assertNull($this->sources($stored)->oldestLiveDay([], $dates));
    }

    public function testLiveDaysAreTheDaysOneSubscriptionMustCountLiveInOrder(): void
    {
        $plain = new TrackedSubscriptionModel(7, 7, '2026-10-12', false);
        $stored = new DayCountsModel([7 => ['2026-10-13' => new DayCountModel(0, 0)]]);
        $dates = ['2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14'];

        self::assertSame(['2026-10-12', '2026-10-14'], $this->sources($stored)->liveDays($plain, $dates));
    }
}
