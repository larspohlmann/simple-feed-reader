<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Pass;

use App\Service\SubscriptionHistory\Model\DayCountModel;
use App\Service\SubscriptionHistory\Model\DayCountsModel;

final class DayTally
{
    /** @var array<int, array<string, DayCountModel>> */
    private array $tallies = [];

    public function add(int $subscriptionId, string $day, DayCountModel $count): void
    {
        $current = $this->tallies[$subscriptionId][$day] ?? DayCountModel::none();
        $this->tallies[$subscriptionId][$day] = $current->plus($count);
    }

    public function counts(): DayCountsModel
    {
        return new DayCountsModel($this->tallies);
    }
}
