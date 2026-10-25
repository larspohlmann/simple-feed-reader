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
use App\Service\SubscriptionHistory\Model\FeedArrivalRateModel;
use App\Service\SubscriptionHistory\Model\SubscriptionActivityModel;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Service\SubscriptionHistory\Pass\DaySources;
use App\Service\SubscriptionHistory\Support\UtcDay;

final readonly class SubscriptionActivityCounter
{
    private const int TOP_FEEDS = 5;

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

        return new SubscriptionActivityModel(
            array_map(
                fn (string $date): ActivityDayModel => $this->dayOf($date, $subscriptions, $sources, $live),
                $window->dates,
            ),
            $this->busiestByArrivals($subscriptions, DayWindowModel::averagedWeekOf($window->dates), $sources, $live),
        );
    }

    /**
     * @param list<TrackedSubscriptionModel> $subscriptions
     * @param list<string>                   $week
     *
     * @return list<FeedArrivalRateModel> busiest first, social feeds left out
     */
    private function busiestByArrivals(
        array $subscriptions,
        array $week,
        DaySources $sources,
        DayCountsModel $live,
    ): array {
        $rates = [];
        $plainFeeds = array_filter(
            $subscriptions,
            static fn (TrackedSubscriptionModel $subscription): bool => !$subscription->isSocial,
        );
        foreach ($plainFeeds as $subscription) {
            $average = $this->arrivedAverageOf($subscription, $week, $sources, $live);
            if ($average > 0) {
                $rates[] = new FeedArrivalRateModel($subscription->subscriptionId, $average);
            }
        }
        usort($rates, FeedArrivalRateModel::busierFirst(...));

        return \array_slice($rates, 0, self::TOP_FEEDS);
    }

    /**
     * @param list<string> $week
     */
    private function arrivedAverageOf(
        TrackedSubscriptionModel $subscription,
        array $week,
        DaySources $sources,
        DayCountsModel $live,
    ): float {
        $knownDays = array_filter(
            $week,
            static fn (string $day): bool => $sources->contributes($subscription, $day)
                && $sources->isKnown($subscription, $day),
        );
        if ($knownDays === []) {
            return 0.0;
        }
        $arrivals = array_map(
            static fn (string $day): int => $sources->countFor($subscription, $day, $live)->arrived,
            $knownDays,
        );

        return array_sum($arrivals) / \count($knownDays);
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
