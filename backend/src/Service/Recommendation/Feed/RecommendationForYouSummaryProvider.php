<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed;

use App\Entity\User;
use App\Enum\RunStatus;
use App\Repository\RecommendationItemRepository;
use App\Repository\RecommendationRunRepository;
use App\Service\Recommendation\Feed\Model\RecommendationForYouSummaryModel;

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
