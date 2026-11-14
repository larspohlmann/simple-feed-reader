<?php

declare(strict_types=1);

namespace App\Service\Library\SubscriptionHistory\Factory;

use App\Repository\SubscriptionDayRepository;
use App\Service\Library\SubscriptionHistory\Model\DayWindowModel;
use App\Service\Library\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Service\Library\SubscriptionHistory\Pass\DaySources;
use App\Service\Library\SubscriptionHistory\RetentionHorizon;

final readonly class DaySourcesFactory
{
    public function __construct(private SubscriptionDayRepository $storedDays, private RetentionHorizon $horizon)
    {
    }

    /**
     * @param list<TrackedSubscriptionModel> $subscriptions
     */
    public function forWindow(array $subscriptions, DayWindowModel $window): DaySources
    {
        return new DaySources(
            $this->storedDays->since(TrackedSubscriptionModel::idsOf($subscriptions), $window->oldest()),
            $this->horizon->asOf($window->today()),
        );
    }
}
