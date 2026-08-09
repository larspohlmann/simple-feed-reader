<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Repository\RecommendationItemRepository;
use App\Repository\RecommendationRunLogRepository;
use App\Repository\RecommendationRunRepository;
use App\Service\Recommendation\Exception\RecommendationRunActiveException;

/**
 * Clears an account's whole for-you list so a fresh run can rebuild it.
 * Children delete in an explicit order — logs, then items, then runs —
 * instead of leaning on DB-level cascades: portable across both suite
 * dialects, and the order lives in the code, not the schema.
 */
final readonly class RecommendationRunPurger
{
    public function __construct(
        private RecommendationRunRepository $runs,
        private RecommendationRunLogRepository $logs,
        private RecommendationItemRepository $items,
    ) {
    }

    /** @throws RecommendationRunActiveException while a run is pending or running */
    public function purge(User $user): void
    {
        $latest = $this->runs->findLatestForUser($user);
        $active = [RecommendationRun::STATUS_PENDING, RecommendationRun::STATUS_RUNNING];
        if (null !== $latest && \in_array($latest->getStatus(), $active, true)) {
            throw new RecommendationRunActiveException();
        }

        $this->logs->deleteForUser($user);
        $this->items->deleteForUser($user);
        $this->runs->deleteForUser($user);
    }
}
