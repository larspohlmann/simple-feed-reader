<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Model;

final readonly class DayCountsModel
{
    /**
     * @param array<int, array<string, DayCountModel>> $bySubscription subscription id => 'Y-m-d' => counts
     */
    public function __construct(private array $bySubscription)
    {
    }

    public function has(int $subscriptionId, string $day): bool
    {
        return isset($this->bySubscription[$subscriptionId][$day]);
    }

    public function of(int $subscriptionId, string $day): DayCountModel
    {
        return $this->bySubscription[$subscriptionId][$day] ?? DayCountModel::none();
    }
}
