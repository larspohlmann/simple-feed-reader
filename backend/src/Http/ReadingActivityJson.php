<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Reading\Model\ReadingActivityModel;
use App\Service\SubscriptionHistory\Model\ActivityDayModel;
use App\Service\SubscriptionHistory\Model\FeedArrivalRateModel;

/**
 * The About page's two 30-day charts, one entry per UTC day, oldest first. `null` marks a day that can no longer be
 * known (history not yet recorded and the entries already pruned); the client draws it as a gap.
 *
 * @phpstan-type ReadingActivityPayload array{
 *     days: list<array{date: string, arrived: int|null, read: int|null}>,
 *     total: int,
 *     arrivedDailyAverage: float|null,
 *     topFeedsByRead: list<array{feedId: int, readCount: int}>,
 *     topFeedsByArrivals: list<array{subscriptionId: int, dailyAverage: float}>,
 * }
 */
final class ReadingActivityJson
{
    /** @return ReadingActivityPayload */
    public static function of(ReadingActivityModel $activity): array
    {
        $average = $activity->daily->arrivedDailyAverage();

        return [
            'days' => array_map(
                static fn (ActivityDayModel $day): array => [
                    'date' => $day->date,
                    'arrived' => $day->arrived(),
                    'read' => $day->read(),
                ],
                $activity->daily->days,
            ),
            'total' => $activity->daily->readTotal(),
            'arrivedDailyAverage' => $average === null ? null : round($average, 1),
            'topFeedsByRead' => $activity->topFeedsByRead,
            'topFeedsByArrivals' => array_map(
                static fn (FeedArrivalRateModel $rate): array => [
                    'subscriptionId' => $rate->subscriptionId,
                    'dailyAverage' => round($rate->dailyAverage, 1),
                ],
                $activity->daily->topFeedsByArrivals,
            ),
        ];
    }

    private function __construct()
    {
    }
}
