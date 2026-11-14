<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\User;
use App\Repository\RecommendationItemRepository;
use App\Repository\RecommendationRunLogRepository;
use App\Repository\RecommendationRunRepository;
use App\Service\Recommendation\Exception\RecommendationRunActiveException;

/**
 * Clears an account's whole "For you" list so a fresh run can rebuild it: logs, then items, then runs, in code rather
 * than through the schema's cascades, so the order holds on both suite dialects.
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
        if (null !== $latest && $latest->getStatus()->isActive()) {
            throw new RecommendationRunActiveException();
        }

        $this->logs->deleteForUser($user);
        $this->items->deleteForUser($user);
        $this->runs->deleteForUser($user);
    }
}
