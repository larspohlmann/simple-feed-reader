<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationRunRepository;
use App\Service\Recommendation\Exception\RecommendationRunCancelledException;
use App\Service\Recommendation\Exception\RecommendationTickLockLostException;

/**
 * Where a tick finds out it must stop.
 *
 * Both reasons are cooperative: a provider call cannot be interrupted, so
 * whatever happened while it ran is only noticed afterwards. The user's stop
 * flips the run's status and leaves the running tick to see it. A lock this
 * tick no longer owns is seen by TickLockKeepalive, whose refresh the store
 * rejected mid-stream and which cannot throw from there (#444).
 *
 * The advancer calls this after each provider call is recorded and before it
 * mutates the run, so a tick that must stop keeps neither the batch the call
 * produced nor the progress that would follow from it — which is exactly
 * what both reasons need: a cancelled run must not march on, and a run
 * another process is now advancing must not be banked twice.
 *
 * The status is read as a scalar rather than from the entity on purpose: the
 * entity is the copy the tick has held since before the call, and the two
 * sides genuinely sit in different processes — a worker tick against a web
 * request. Only the database knows what happened in between.
 */
final readonly class RecommendationCancellationCheckpoint
{
    public function __construct(
        private RecommendationRunRepository $runs,
        private TickLockKeepalive $keepalive,
    ) {
    }

    /**
     * The lock is checked first, and without a query: it is a field this
     * process already knows, and a tick that has lost its lock must stop
     * whatever the run's status says.
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
        return RecommendationRun::STATUS_CANCELLED === $this->runs->statusOf($run->getId() ?? 0);
    }
}
