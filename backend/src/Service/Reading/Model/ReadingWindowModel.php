<?php

declare(strict_types=1);

namespace App\Service\Reading\Model;

use App\Service\Clock\Model\ViewerTimeZoneModel;

/**
 * The last N calendar days of a reading-activity chart in the viewer's zone, each listed so a quiet day zero-fills.
 * `sinceUtc` is the oldest day's local midnight in UTC, the wall clock `viewedAt` is persisted in. Days step by
 * `+1 day`, never by a count of hours, so a daylight-saving change keeps every bucket on its own calendar day.
 */
final readonly class ReadingWindowModel
{
    /**
     * @param list<string> $localDates oldest first, 'Y-m-d' in the viewer's zone
     */
    private function __construct(
        public array $localDates,
        public \DateTimeImmutable $sinceUtc,
    ) {
    }

    public static function lastDays(int $dayCount, ViewerTimeZoneModel $viewer, \DateTimeImmutable $nowUtc): self
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
