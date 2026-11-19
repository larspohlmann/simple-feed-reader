<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Service\Library\Subscription\Model\SubscriptionTalliesModel;

final readonly class SurfaceTalliesModel
{
    public function __construct(
        public SubscriptionTalliesModel $subscriptions,
        public TrendingUnopenedModel $trending,
    ) {
    }
}
