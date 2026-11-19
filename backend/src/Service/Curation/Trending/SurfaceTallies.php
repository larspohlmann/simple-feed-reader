<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\SurfaceTalliesModel;
use App\Service\Library\Subscription\SubscriptionTallyReader;

final readonly class SurfaceTallies
{
    public function __construct(
        private SubscriptionTallyReader $subscriptionTallies,
        private TrendingStories $trendingStories,
    ) {
    }

    public function buildTallies(User $user, TrendingWindow $trendingWindow): SurfaceTalliesModel
    {
        return new SurfaceTalliesModel(
            $this->subscriptionTallies->countTallies($user),
            $this->trendingStories->unopenedFor($user, $trendingWindow),
        );
    }
}
