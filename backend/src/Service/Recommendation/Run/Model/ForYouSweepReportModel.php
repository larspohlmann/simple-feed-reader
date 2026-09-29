<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

/**
 * The outcome of one For You sweep (#333): how many runs it started, how many
 * active runs it advanced by one tick, and how many are still active after.
 */
final readonly class ForYouSweepReportModel
{
    public function __construct(
        public int $startedRuns,
        public int $advancedRuns,
        public int $activeRuns,
    ) {
    }
}
