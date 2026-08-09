<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The outcome of one For You sweep (#333): how many runs it started, how many
 * active runs it advanced by one tick, and how many are still active after.
 */
final readonly class ForYouSweepReport
{
    public function __construct(
        public int $startedRuns,
        public int $advancedRuns,
        public int $activeRuns,
    ) {
    }

    /**
     * @return array{startedRuns: int, advancedRuns: int, activeRuns: int}
     */
    public function toArray(): array
    {
        return [
            'startedRuns' => $this->startedRuns,
            'advancedRuns' => $this->advancedRuns,
            'activeRuns' => $this->activeRuns,
        ];
    }
}
