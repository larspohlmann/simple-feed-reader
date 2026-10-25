<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Pass;

use App\Service\SubscriptionHistory\Model\DayCountModel;
use App\Service\SubscriptionHistory\Model\DayCountsModel;
use App\Service\SubscriptionHistory\Model\RetentionHorizonModel;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;

/** Where a subscription's day comes from: its stored row, a live count while the entries last, or nowhere. */
final readonly class DaySources
{
    public function __construct(private DayCountsModel $stored, private RetentionHorizonModel $horizon)
    {
    }

    public function contributes(TrackedSubscriptionModel $subscription, string $day): bool
    {
        return $day >= $subscription->createdDay;
    }

    public function isKnown(TrackedSubscriptionModel $subscription, string $day): bool
    {
        return $this->stored->has($subscription->subscriptionId, $day)
            || $this->horizon->isCountable($subscription, $day);
    }

    public function needsLive(TrackedSubscriptionModel $subscription, string $day): bool
    {
        return $this->contributes($subscription, $day)
            && !$this->stored->has($subscription->subscriptionId, $day)
            && $this->horizon->isCountable($subscription, $day);
    }

    /**
     * @param list<TrackedSubscriptionModel> $subscriptions
     * @param list<string>                   $dates oldest first
     */
    public function oldestLiveDay(array $subscriptions, array $dates): ?string
    {
        foreach ($dates as $day) {
            foreach ($subscriptions as $subscription) {
                if ($this->needsLive($subscription, $day)) {
                    return $day;
                }
            }
        }

        return null;
    }

    public function countFor(TrackedSubscriptionModel $subscription, string $day, DayCountsModel $live): DayCountModel
    {
        if ($this->stored->has($subscription->subscriptionId, $day)) {
            return $this->stored->of($subscription->subscriptionId, $day);
        }

        return $live->of($subscription->subscriptionId, $day);
    }
}
