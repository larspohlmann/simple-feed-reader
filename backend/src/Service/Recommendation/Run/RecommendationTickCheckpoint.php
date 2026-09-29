<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Enum\RunStatus;
use App\Repository\RecommendationRunRepository;
use App\Service\Recommendation\Exception\RecommendationRunCancelledException;
use App\Service\Recommendation\Exception\RecommendationTickLockLostException;

/**
 * Where a tick finds out it must stop: the user cancelled the run, or TickLockKeepalive saw another process take the
 * lock. Called after each provider call is recorded and before the run is mutated, so a stopped tick banks nothing.
 * The status is read from the database: the entity is the tick's copy from before the call.
 */
final readonly class RecommendationTickCheckpoint
{
    public function __construct(
        private RecommendationRunRepository $runs,
        private TickLockKeepalive $keepalive,
    ) {
    }

    /**
     * The lock first, without a query: a tick that lost its lock stops whatever the status says.
     *
     * @throws RecommendationTickLockLostException when another process took this tick's lock
     * @throws RecommendationRunCancelledException when the run was stopped meanwhile
     */
    public function guard(RecommendationRun $run): void
    {
        if ($this->keepalive->hasLostTheLock()) {
            throw new RecommendationTickLockLostException();
        }

        if ($this->wasStopped($run)) {
            throw new RecommendationRunCancelledException();
        }
    }

    private function wasStopped(RecommendationRun $run): bool
    {
        return RunStatus::Cancelled === $this->runs->statusOf($run->requireId());
    }
}
