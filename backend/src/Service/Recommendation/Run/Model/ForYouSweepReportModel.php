<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

/** One For You sweep: the runs it started, the active runs it advanced one tick, and the runs still active after. */
final readonly class ForYouSweepReportModel
{
    public function __construct(
        public int $startedRuns,
        public int $advancedRuns,
        public int $activeRuns,
    ) {
    }
}
