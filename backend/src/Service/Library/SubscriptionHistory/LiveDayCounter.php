<?php

declare(strict_types=1);

namespace App\Service\Library\SubscriptionHistory;

use App\Repository\SubscriptionActivityRepository;
use App\Service\Library\SubscriptionHistory\Model\DayCountModel;
use App\Service\Library\SubscriptionHistory\Model\DayCountsModel;
use App\Service\Library\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Service\Library\SubscriptionHistory\Pass\DayTally;
use App\Service\Library\SubscriptionHistory\Support\UtcDay;

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
        $since = UtcDay::start($oldestDay);
        $tally = new DayTally();
        $this->tallyArrivals($tally, $subscriptions, $since);
        foreach ($this->activity->readCountsSince(TrackedSubscriptionModel::idsOf($subscriptions), $since) as $reads) {
            $tally->add($reads['subscriptionId'], $reads['day'], new DayCountModel(0, $reads['count']));
        }

        return $tally->counts();
    }

    /**
     * @param list<TrackedSubscriptionModel> $subscriptions
     */
    private function tallyArrivals(DayTally $tally, array $subscriptions, \DateTimeImmutable $since): void
    {
        $subscribersByFeed = [];
        foreach ($subscriptions as $subscription) {
            $subscribersByFeed[$subscription->feedId][] = $subscription;
        }

        foreach ($this->activity->arrivalCountsSince(array_keys($subscribersByFeed), $since) as $arrivals) {
            $subscribers = array_filter(
                $subscribersByFeed[$arrivals['feedId']],
                static fn (TrackedSubscriptionModel $subscriber): bool => $arrivals['day'] > $subscriber->createdDay,
            );
            foreach ($subscribers as $subscriber) {
                $tally->add($subscriber->subscriptionId, $arrivals['day'], new DayCountModel($arrivals['count'], 0));
            }
        }
    }
}
