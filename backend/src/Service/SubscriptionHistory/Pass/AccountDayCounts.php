<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Pass;

use App\Service\SubscriptionHistory\Model\ActivityDayModel;
use App\Service\SubscriptionHistory\Model\DayCountModel;
use App\Service\SubscriptionHistory\Model\DayCountsModel;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;

/** One account's subscriptions with where each day comes from and the live counts loaded for them. */
final readonly class AccountDayCounts
{
    /**
     * @param list<TrackedSubscriptionModel> $subscriptions
     */
    public function __construct(
        public array $subscriptions,
        private DaySources $sources,
        private DayCountsModel $live,
    ) {
    }

    public function day(string $date): ActivityDayModel
    {
        $total = DayCountModel::none();
        foreach ($this->subscriptions as $subscription) {
            if (!$this->sources->contributes($subscription, $date)) {
                continue;
            }
            if (!$this->sources->isKnown($subscription, $date)) {
                return ActivityDayModel::unknown($date);
            }
            $total = $total->plus($this->sources->countFor($subscription, $date, $this->live));
        }

        return ActivityDayModel::known($date, $total);
    }

    /**
     * @param list<string> $days
     */
    public function arrivedAverage(TrackedSubscriptionModel $subscription, array $days): float
    {
        $knownDays = array_filter(
            $days,
            fn (string $day): bool => $this->sources->contributes($subscription, $day)
                && $this->sources->isKnown($subscription, $day),
        );
        if ($knownDays === []) {
            return 0.0;
        }
        $arrivals = array_map(
            fn (string $day): int => $this->sources->countFor($subscription, $day, $this->live)->arrived,
            $knownDays,
        );

        return array_sum($arrivals) / \count($knownDays);
    }
}
