<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory\Model;

use App\Service\SubscriptionHistory\Model\ActivityDayModel;
use App\Service\SubscriptionHistory\Model\DayCountModel;
use App\Service\SubscriptionHistory\Model\SubscriptionActivityModel;
use PHPUnit\Framework\TestCase;

final class SubscriptionActivityModelTest extends TestCase
{
    /** @param list<int|null> $arrivals oldest first, today last */
    private function activity(array $arrivals): SubscriptionActivityModel
    {
        $days = [];
        foreach ($arrivals as $index => $arrived) {
            $date = sprintf('2026-10-%02d', $index + 1);
            $days[] = $arrived === null
                ? ActivityDayModel::unknown($date)
                : ActivityDayModel::known($date, new DayCountModel($arrived, 1));
        }

        return new SubscriptionActivityModel($days, []);
    }

    public function testAveragesTheSevenCompletedDaysAndLeavesTodayOut(): void
    {
        self::assertSame(4.0, $this->activity([100, 1, 2, 3, 4, 5, 6, 7, 999])->arrivedDailyAverage());
    }

    public function testAveragesOnlyTheKnownDaysOfThatWeek(): void
    {
        self::assertSame(5.0, $this->activity([null, null, null, null, null, 4, 6, 0])->arrivedDailyAverage());
    }

    public function testNoKnownDayInTheWeekHasNoAverage(): void
    {
        self::assertNull($this->activity([null, null, null, null, null, null, null, 3])->arrivedDailyAverage());
    }

    public function testTheReadTotalSkipsUnknownDays(): void
    {
        self::assertSame(2, $this->activity([null, 5, 5])->readTotal());
    }
}
