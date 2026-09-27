<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Ai\Completion\RetryPlan;

/**
 * Which driver ticks the run (#344). Only the worker owns its process; poll and sweep (the maintenance cron's
 * HTTP call) run inside a bounded web request, so they clamp their wave and never wait out a rate limit.
 */
enum TickDriver
{
    case Worker;
    case Poll;
    case Sweep;

    public function retryPlan(): RetryPlan
    {
        return self::Worker === $this ? RetryPlan::blocking() : RetryPlan::deferring();
    }
}
