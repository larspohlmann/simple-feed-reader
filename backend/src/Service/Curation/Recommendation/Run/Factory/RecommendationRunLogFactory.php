<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Factory;

use App\Entity\ProfileRun;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Recommendation\Run\Model\CallSlotModel;
use Symfony\Component\Clock\ClockInterface;

final readonly class RecommendationRunLogFactory
{
    public function __construct(
        private RecommendationRunLogRepository $logs,
        private ClockInterface $clock,
    ) {
    }

    public function create(
        RecommendationRun $run,
        CallSlotModel $slot,
        string $renderedRequest,
    ): RecommendationRunLog {
        return RecommendationRunLog::forRun(
            $run,
            $slot->phase,
            $slot->batchNumber,
            $this->nextAttempt($run, $slot),
            $renderedRequest,
            $this->clock->now(),
        );
    }

    public function createForProfileRun(ProfileRun $profileRun, string $renderedRequest): RecommendationRunLog
    {
        return RecommendationRunLog::forProfileRun(
            $profileRun,
            $this->logs->countProfileRunAttempts($profileRun) + 1,
            $renderedRequest,
            $this->clock->now(),
        );
    }

    /** Derived from the rows already recorded, so the recorder cannot disagree with its own rows. */
    private function nextAttempt(RecommendationRun $run, CallSlotModel $slot): int
    {
        return $this->logs->countAttempts($run, $slot->phase, $slot->batchNumber) + 1;
    }
}
