<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Reading\ReadingActivity;

/**
 * The wire shape of the reading-activity chart (#896): one entry per day of the
 * window in order, each carrying the day and how many articles the account
 * opened on it, plus the window's total. Quiet days are present with a count of
 * zero so the client draws a continuous axis rather than skipping gaps.
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
    public static function of(ReadingActivity $activity): array
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
