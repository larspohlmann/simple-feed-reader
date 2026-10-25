<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Factory;

use App\Repository\SubscriptionDayRepository;
use App\Service\SubscriptionHistory\Model\DayWindowModel;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Service\SubscriptionHistory\Pass\DaySources;
use App\Service\SubscriptionHistory\RetentionHorizon;

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
