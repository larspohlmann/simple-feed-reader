<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Model;

final readonly class SubscriptionActivityModel
{
    /**
     * @param list<ActivityDayModel>     $days               oldest first, today last
     * @param list<FeedArrivalRateModel> $topFeedsByArrivals busiest first
     */
    public function __construct(public array $days, public array $topFeedsByArrivals)
    {
    }

    public function arrivedDailyAverage(): ?float
    {
        $week = DayWindowModel::averagedWeekOf($this->days);
        $known = array_filter(
            array_map(static fn (ActivityDayModel $day): ?int => $day->arrived(), $week),
            static fn (?int $arrived): bool => $arrived !== null,
        );

        return $known === [] ? null : array_sum($known) / \count($known);
    }

    public function readTotal(): int
    {
        return array_sum(array_map(static fn (ActivityDayModel $day): int => $day->read() ?? 0, $this->days));
    }
}
