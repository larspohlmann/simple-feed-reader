<?php

declare(strict_types=1);

namespace App\Service\Library\SubscriptionHistory;

use App\Entity\User;
use App\Repository\SubscriptionActivityRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\SubscriptionHistory\Factory\DaySourcesFactory;
use App\Service\Library\SubscriptionHistory\Model\DayWindowModel;
use App\Service\Library\SubscriptionHistory\Model\FeedArrivalRateModel;
use App\Service\Library\SubscriptionHistory\Model\SubscriptionActivityModel;
use App\Service\Library\SubscriptionHistory\Pass\AccountDayCounts;
use App\Service\Library\SubscriptionHistory\Support\UtcDay;

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
        $subscriptions = $this->activity->plainSubscriptionsOf($user->requireId());
        $sources = $this->daySourcesFactory->forWindow($subscriptions, $window);
        $live = $this->liveCounter->since(
            $subscriptions,
            $sources->oldestLiveDay($subscriptions, $window->dates) ?? $window->today(),
        );

        $counts = new AccountDayCounts($subscriptions, $sources, $live);

        return new SubscriptionActivityModel(
            array_map($counts->day(...), $window->dates),
            $this->busiestByArrivals($counts, DayWindowModel::averagedWeekOf($window->dates)),
        );
    }

    /**
     * @param list<string> $week
     *
     * @return list<FeedArrivalRateModel> busiest first
     */
    private function busiestByArrivals(AccountDayCounts $counts, array $week): array
    {
        $rates = [];
        foreach ($counts->subscriptions as $subscription) {
            $average = $counts->arrivedAverage($subscription, $week);
            if ($average > 0) {
                $rates[] = new FeedArrivalRateModel($subscription->subscriptionId, $average);
            }
        }
        usort($rates, FeedArrivalRateModel::busierFirst(...));

        return \array_slice($rates, 0, self::TOP_FEEDS);
    }
}
