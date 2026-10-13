<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Fetch\Support\CapacityGuard;
use App\Service\Social\Model\TimelineSlotsModel;
use App\Service\Social\Pass\TimelineAccounts;
use App\Service\Social\Pass\TimelineRound;

/** Starts one refresh's private-feed round, its reads capped per account and per Mastodon instance or Bluesky PDS. */
final readonly class TimelineRounds
{
    public function __construct(
        private TimelineSources $sources,
        private int $accountConcurrency,
        private int $hostConcurrency,
    ) {
        CapacityGuard::atLeastOne($accountConcurrency, 'Per-account concurrency');
        CapacityGuard::atLeastOne($hostConcurrency, 'Per-server concurrency');
    }

    public function start(): TimelineRound
    {
        return new TimelineRound(
            $this->sources,
            new TimelineAccounts(),
            new TimelineSlotsModel($this->accountConcurrency, $this->hostConcurrency),
        );
    }
}
