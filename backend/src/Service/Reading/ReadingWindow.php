<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Service\Recommendation\ViewerTimeZone;

/**
 * The last N calendar days a reading-activity chart covers (#896), cut in the
 * viewer's own timezone. `localDates` is one 'Y-m-d' per day, oldest first, so
 * the chart can zero-fill a quiet day instead of dropping it. `sinceUtc` is the
 * lower bound the read query binds: local midnight of the oldest day expressed
 * in UTC, because that is the wall clock Doctrine persists `viewedAt` in.
 *
 * Days are stepped by `+1 day` from local midnight rather than by an hour count,
 * so a daylight-saving change inside the window keeps every bucket on its own
 * calendar day — the reason MonthWindow advances by a whole month.
 */
final readonly class ReadingWindow
{
    /**
     * @param list<string> $localDates oldest first, 'Y-m-d' in the viewer's zone
     */
    private function __construct(
        public array $localDates,
        public \DateTimeImmutable $sinceUtc,
    ) {
    }

    public static function lastDays(int $dayCount, ViewerTimeZone $viewer, \DateTimeImmutable $nowUtc): self
    {
        $today = $nowUtc->setTimezone($viewer->zone)->setTime(0, 0);
        $oldest = $today->modify(sprintf('-%d days', $dayCount - 1));

        $localDates = [];
        for ($day = $oldest; $day <= $today; $day = $day->modify('+1 day')) {
            $localDates[] = $day->format('Y-m-d');
        }

        return new self($localDates, $oldest->setTimezone(new \DateTimeZone('UTC')));
    }
}
