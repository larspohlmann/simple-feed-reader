<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory;

use App\Repository\SubscriptionActivityRepository;
use App\Service\SubscriptionHistory\Model\DayCountsModel;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Service\SubscriptionHistory\Pass\DayTally;
use App\Service\SubscriptionHistory\Support\UtcDay;

/** Counts days straight from entries and entry states, which only works while retention still holds them. */
final readonly class LiveDayCounter
{
    public function __construct(private SubscriptionActivityRepository $activity)
    {
    }

    /**
     * @param list<TrackedSubscriptionModel> $subscriptions
     */
    public function since(array $subscriptions, string $oldestDay): DayCountsModel
    {
        $createdDays = [];
        foreach ($subscriptions as $subscription) {
            $createdDays[$subscription->subscriptionId] = $subscription->createdDay;
        }
        $subscriptionIds = array_keys($createdDays);
        $since = UtcDay::start($oldestDay);
        $tally = new DayTally();

        foreach ($this->activity->arrivalsSince($subscriptionIds, $since) as $arrival) {
            $day = UtcDay::of($arrival['happenedAt']);
            if ($day > $createdDays[$arrival['subscriptionId']]) {
                $tally->addArrival($arrival['subscriptionId'], $day);
            }
        }
        foreach ($this->activity->viewsSince($subscriptionIds, $since) as $view) {
            $tally->addRead($view['subscriptionId'], UtcDay::of($view['happenedAt']));
        }

        return $tally->counts();
    }
}
