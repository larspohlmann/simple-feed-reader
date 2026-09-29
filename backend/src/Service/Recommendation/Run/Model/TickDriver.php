<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

use App\Service\Ai\Completion\Model\RetryPlanModel;

/**
 * Which driver ticks the run. Only the worker owns its process; poll and sweep (the maintenance cron's HTTP call) run
 * inside a bounded web request, so they clamp their wave and never wait out a rate limit.
 */
enum TickDriver
{
    case Worker;
    case Poll;
    case Sweep;

    public function retryPlan(): RetryPlanModel
    {
        return self::Worker === $this ? RetryPlanModel::blocking() : RetryPlanModel::deferring();
    }
}
