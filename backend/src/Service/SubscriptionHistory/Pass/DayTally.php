<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Pass;

use App\Service\SubscriptionHistory\Model\DayCountModel;
use App\Service\SubscriptionHistory\Model\DayCountsModel;

final class DayTally
{
    /** @var array<int, array<string, array{arrived?: int, read?: int}>> */
    private array $tallies = [];

    public function addArrival(int $subscriptionId, string $day): void
    {
        $arrived = $this->tallies[$subscriptionId][$day]['arrived'] ?? 0;
        $this->tallies[$subscriptionId][$day]['arrived'] = $arrived + 1;
    }

    public function addRead(int $subscriptionId, string $day): void
    {
        $read = $this->tallies[$subscriptionId][$day]['read'] ?? 0;
        $this->tallies[$subscriptionId][$day]['read'] = $read + 1;
    }

    public function counts(): DayCountsModel
    {
        return new DayCountsModel(array_map(
            static fn (array $days): array => array_map(
                static fn (array $tally): DayCountModel => new DayCountModel(
                    $tally['arrived'] ?? 0,
                    $tally['read'] ?? 0,
                ),
                $days,
            ),
            $this->tallies,
        ));
    }
}
