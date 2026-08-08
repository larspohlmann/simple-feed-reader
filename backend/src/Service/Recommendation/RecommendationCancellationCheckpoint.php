<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationRunRepository;
use App\Service\Recommendation\Exception\RecommendationRunCancelledException;

/**
 * Where a tick finds out the user stopped the run underneath it.
 *
 * Cancellation is cooperative: a provider call cannot be interrupted, so the
 * stop endpoint only flips the status and leaves the running tick to notice.
 * The advancer calls this after each provider call is recorded and before it
 * mutates the run, so a stopped run keeps neither the batch the call produced
 * nor the progress that would follow from it.
 *
 * The status is read as a scalar rather than from the entity on purpose: the
 * entity is the copy the tick has held since before the call, and the two
 * sides genuinely sit in different processes — a worker tick against a web
 * request. Only the database knows what happened in between.
 */
final readonly class RecommendationCancellationCheckpoint
{
    public function __construct(private RecommendationRunRepository $runs)
    {
    }

    /** @throws RecommendationRunCancelledException when the run was stopped meanwhile */
    public function guard(RecommendationRun $run): void
    {
        if (RecommendationRun::STATUS_CANCELLED === $this->runs->statusOf($run->getId() ?? 0)) {
            throw new RecommendationRunCancelledException();
        }
    }
}
