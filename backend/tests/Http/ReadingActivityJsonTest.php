<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\ReadingActivityJson;
use App\Service\Reading\Model\ReadingActivityModel;
use App\Service\SubscriptionHistory\Model\ActivityDayModel;
use App\Service\SubscriptionHistory\Model\DayCountModel;
use App\Service\SubscriptionHistory\Model\SubscriptionActivityModel;
use PHPUnit\Framework\TestCase;

final class ReadingActivityJsonTest extends TestCase
{
    public function testShapesEachDayKeepsTheOrderTotalsReadsAndCarriesTheFeedRanking(): void
    {
        $payload = ReadingActivityJson::of(new ReadingActivityModel(
            new SubscriptionActivityModel([
                ActivityDayModel::known('2026-09-05', new DayCountModel(0, 0)),
                ActivityDayModel::known('2026-09-06', new DayCountModel(5, 3)),
                ActivityDayModel::known('2026-09-07', new DayCountModel(2, 1)),
            ]),
            [['feedId' => 7, 'readCount' => 42], ['feedId' => 3, 'readCount' => 10]],
        ));

        self::assertSame(
            [
                ['date' => '2026-09-05', 'arrived' => 0, 'read' => 0],
                ['date' => '2026-09-06', 'arrived' => 5, 'read' => 3],
                ['date' => '2026-09-07', 'arrived' => 2, 'read' => 1],
            ],
            $payload['days'],
        );
        self::assertSame(4, $payload['total']);
        self::assertSame(
            [['feedId' => 7, 'readCount' => 42], ['feedId' => 3, 'readCount' => 10]],
            $payload['topFeedsByRead'],
        );
    }

    public function testUnknownDaysStayNullAndTheAverageIsRoundedToOneDecimal(): void
    {
        $days = [ActivityDayModel::unknown('2026-09-01')];
        foreach ([1, 0, 0, 0, 0, 0, 0] as $index => $arrived) {
            $days[] = ActivityDayModel::known('2026-09-0' . ($index + 2), new DayCountModel($arrived, 0));
        }
        $days[] = ActivityDayModel::known('2026-09-09', new DayCountModel(9, 0));

        $payload = ReadingActivityJson::of(new ReadingActivityModel(new SubscriptionActivityModel($days), []));

        self::assertNull($payload['days'][0]['arrived']);
        self::assertNull($payload['days'][0]['read']);
        self::assertSame(0.1, $payload['arrivedDailyAverage']);
    }

    public function testAnAccountWithNoKnownDaysHasNoAverage(): void
    {
        $payload = ReadingActivityJson::of(new ReadingActivityModel(
            new SubscriptionActivityModel([ActivityDayModel::unknown('2026-09-06')]),
            [],
        ));

        self::assertNull($payload['arrivedDailyAverage']);
        self::assertSame(0, $payload['total']);
        self::assertSame([], $payload['topFeedsByRead']);
    }
}
