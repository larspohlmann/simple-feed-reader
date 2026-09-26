<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\User;
use App\Repository\EntryStateRepository;
use App\Service\Recommendation\ViewerTimeZone;
use Psr\Clock\ClockInterface;

/**
 * How many articles the account opened on each of the last WINDOW_DAYS days, in the viewer's own timezone
 * (#896). Bucketed in PHP, not the database: `viewedAt` is naive UTC and the buckets are cut in the viewer's
 * zone, which no portable DQL expression can shift before grouping.
 */
final readonly class ReadingActivityCounter
{
    private const int WINDOW_DAYS = 30;
    private const int TOP_FEEDS = 5;

    public function __construct(
        private EntryStateRepository $states,
        private ClockInterface $clock,
    ) {
    }

    public function daily(User $user, ViewerTimeZone $viewer): ReadingActivity
    {
        $window = ReadingWindow::lastDays(self::WINDOW_DAYS, $viewer, $this->nowUtc());
        $userId = $user->requireId();

        $countsByDay = $this->countByLocalDay(
            $this->states->viewedAtSince($userId, $window->sinceUtc),
            $viewer,
        );

        return new ReadingActivity(
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
    private function countByLocalDay(array $viewedAt, ViewerTimeZone $viewer): array
    {
        $countsByDay = [];
        foreach ($viewedAt as $instant) {
            $day = $instant->setTimezone($viewer->zone)->format('Y-m-d');
            $countsByDay[$day] = ($countsByDay[$day] ?? 0) + 1;
        }

        return $countsByDay;
    }

    private function nowUtc(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('UTC'));
    }
}
