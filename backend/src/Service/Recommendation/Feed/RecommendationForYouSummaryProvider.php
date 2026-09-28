<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed;

use App\Entity\User;
use App\Enum\RunStatus;
use App\Repository\RecommendationItemRepository;
use App\Repository\RecommendationRunRepository;
use App\Service\Recommendation\Feed\Model\RecommendationForYouSummaryModel;

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

    public function forUser(User $user): RecommendationForYouSummaryModel
    {
        $newestCompletedRun = $this->runs->findLatestForUser($user, RunStatus::Completed);

        return new RecommendationForYouSummaryModel(
            $this->items->countForYou($user->requireId()),
            $this->items->countForYouIncludingRead($user->requireId()),
            $newestCompletedRun?->getCompletedAt(),
            $newestCompletedRun?->getId(),
        );
    }
}
