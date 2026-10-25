<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory;

use App\Entity\User;
use App\Repository\SubscriptionActivityRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\SubscriptionHistory\Factory\DaySourcesFactory;
use App\Service\SubscriptionHistory\Model\ActivityDayModel;
use App\Service\SubscriptionHistory\Model\DayCountModel;
use App\Service\SubscriptionHistory\Model\DayCountsModel;
use App\Service\SubscriptionHistory\Model\DayWindowModel;
use App\Service\SubscriptionHistory\Model\SubscriptionActivityModel;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Service\SubscriptionHistory\Pass\DaySources;
use App\Service\SubscriptionHistory\Support\UtcDay;

final readonly class SubscriptionActivityCounter
{
    public function __construct(
        private SubscriptionActivityRepository $activity,
        private DaySourcesFactory $daySourcesFactory,
        private LiveDayCounter $liveCounter,
        private NaiveUtcClock $clock,
    ) {
    }

    public function lastDays(User $user): SubscriptionActivityModel
    {
        $window = DayWindowModel::lastDays(UtcDay::of($this->clock->now()));
        $subscriptions = $this->activity->trackedSubscriptionsOf($user->requireId());
        $sources = $this->daySourcesFactory->forWindow($subscriptions, $window);
        $live = $this->liveCounter->since(
            $subscriptions,
            $sources->oldestLiveDay($subscriptions, $window->dates) ?? $window->today(),
        );

        return new SubscriptionActivityModel(array_map(
            fn (string $date): ActivityDayModel => $this->dayOf($date, $subscriptions, $sources, $live),
            $window->dates,
        ));
    }

    /**
     * @param list<TrackedSubscriptionModel> $subscriptions
     */
    private function dayOf(
        string $date,
        array $subscriptions,
        DaySources $sources,
        DayCountsModel $live,
    ): ActivityDayModel {
        $total = DayCountModel::none();
        foreach ($subscriptions as $subscription) {
            if (!$sources->contributes($subscription, $date)) {
                continue;
            }
            if (!$sources->isKnown($subscription, $date)) {
                return ActivityDayModel::unknown($date);
            }
            $total = $total->plus($sources->countFor($subscription, $date, $live));
        }

        return ActivityDayModel::known($date, $total);
    }
}
