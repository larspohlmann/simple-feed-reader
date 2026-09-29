<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Reading\Model\ReadingActivityModel;

/**
 * The reading-activity chart: one entry per day of the window, in order, quiet days at zero so the client draws a
 * continuous axis, plus the window's total.
 *
 * @phpstan-type ReadingActivityPayload array{
 *     days: list<array{date: string, count: int}>,
 *     total: int,
 *     topFeedsByRead: list<array{feedId: int, readCount: int}>,
 * }
 */
final class ReadingActivityJson
{
    /** @return ReadingActivityPayload */
    public static function of(ReadingActivityModel $activity): array
    {
        $days = [];
        $total = 0;
        foreach ($activity->localDates as $date) {
            $count = $activity->countsByDay[$date] ?? 0;
            $days[] = ['date' => $date, 'count' => $count];
            $total += $count;
        }

        return ['days' => $days, 'total' => $total, 'topFeedsByRead' => $activity->topFeedsByRead];
    }

    private function __construct()
    {
    }
}
