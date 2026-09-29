<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\User;
use App\Repository\EntryStateRepository;
use App\Service\Clock\Model\ViewerTimeZoneModel;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Reading\Model\ReadingActivityModel;
use App\Service\Reading\Model\ReadingWindowModel;

/**
 * How many articles the account opened on each of the last WINDOW_DAYS days, in the viewer's own timezone. Bucketed
 * in PHP, not the database: `viewedAt` is naive UTC, and no portable DQL expression can shift it into the viewer's
 * zone before grouping.
 */
final readonly class ReadingActivityCounter
{
    private const int WINDOW_DAYS = 30;
    private const int TOP_FEEDS = 5;

    public function __construct(
        private EntryStateRepository $states,
        private NaiveUtcClock $clock,
    ) {
    }

    public function daily(User $user, ViewerTimeZoneModel $viewer): ReadingActivityModel
    {
        $window = ReadingWindowModel::lastDays(self::WINDOW_DAYS, $viewer, $this->clock->now());
        $userId = $user->requireId();

        $countsByDay = $this->countByLocalDay(
            $this->states->viewedAtSince($userId, $window->sinceUtc),
            $viewer,
        );

        return new ReadingActivityModel(
            $window->localDates,
            $countsByDay,
            $this->states->readCountsByFeed($userId, self::TOP_FEEDS),
        );
    }

    /**
     * @param list<\DateTimeImmutable> $viewedAt
     *
     * @return array<string, int> local 'Y-m-d' => articles opened
     */
    private function countByLocalDay(array $viewedAt, ViewerTimeZoneModel $viewer): array
    {
        $countsByDay = [];
        foreach ($viewedAt as $instant) {
            $day = $instant->setTimezone($viewer->zone)->format('Y-m-d');
            $countsByDay[$day] = ($countsByDay[$day] ?? 0) + 1;
        }

        return $countsByDay;
    }
}
