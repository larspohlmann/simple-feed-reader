<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Model;

final readonly class FeedArrivalRateModel
{
    public function __construct(public int $subscriptionId, public float $dailyAverage)
    {
    }

    public static function busierFirst(self $left, self $right): int
    {
        return $right->dailyAverage <=> $left->dailyAverage ?: $left->subscriptionId <=> $right->subscriptionId;
    }
}
