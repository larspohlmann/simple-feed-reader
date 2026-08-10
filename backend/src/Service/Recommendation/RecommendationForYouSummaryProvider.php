<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\User;
use App\Repository\RecommendationItemRepository;
use App\Repository\RecommendationRunRepository;

/**
 * Builds the for-you summary from two independent reads: the deduped item
 * count and the newest completed run's timestamp. Kept as its own service so
 * Task 3's purge endpoint can reuse it without re-deriving either number.
 */
final readonly class RecommendationForYouSummaryProvider
{
    public function __construct(
        private RecommendationItemRepository $items,
        private RecommendationRunRepository $runs,
    ) {
    }

    public function forUser(User $user): RecommendationForYouSummary
    {
        $newestCompletedRun = $this->runs->newestCompletedRun($user);

        return new RecommendationForYouSummary(
            $this->items->countForYou((int) $user->getId()),
            $newestCompletedRun?->getCompletedAt(),
            $newestCompletedRun?->getId(),
        );
    }
}
