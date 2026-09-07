<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The wire shape of the reading-activity chart (#896): one entry per day of the
 * window in order, each carrying the day and how many articles the account
 * opened on it, plus the window's total. Quiet days are present with a count of
 * zero so the client draws a continuous axis rather than skipping gaps.
 *
 * The named shape is exported so ReadingActivityView can declare its return
 * type against it: a key renamed here without a matching update there is a
 * level-max PHPStan error at the call site, not a silent wire break.
 *
 * @phpstan-type ReadingActivityPayload array{
 *     days: list<array{date: string, count: int}>,
 *     total: int,
 *     topFeedsByRead: list<array{feedId: int, readCount: int}>,
 * }
 */
final class ReadingActivityJson
{
    /**
     * @param list<string>                             $localDates oldest first, one 'Y-m-d' per day
     * @param array<string, int>                       $countsByDay local 'Y-m-d' => articles opened
     * @param list<array{feedId: int, readCount: int}> $topFeedsByRead busiest feed first
     *
     * @return ReadingActivityPayload
     */
    public static function of(array $localDates, array $countsByDay, array $topFeedsByRead): array
    {
        $days = [];
        $total = 0;
        foreach ($localDates as $date) {
            $count = $countsByDay[$date] ?? 0;
            $days[] = ['date' => $date, 'count' => $count];
            $total += $count;
        }

        return ['days' => $days, 'total' => $total, 'topFeedsByRead' => $topFeedsByRead];
    }

    private function __construct()
    {
    }
}
