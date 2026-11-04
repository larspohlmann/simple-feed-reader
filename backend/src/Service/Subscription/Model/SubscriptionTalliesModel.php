<?php

declare(strict_types=1);

namespace App\Service\Subscription\Model;

use App\Service\Trending\Model\TrendingCountsModel;

final readonly class SubscriptionTalliesModel
{
    /**
     * @param array<int, int>                                            $unreadCounts subscription id => unread
     * @param array<int, int>                                            $entryCounts  subscription id => entries
     * @param array{favorites: int, kept: int, shared: int, viewed: int} $flagCounts
     */
    public function __construct(
        public array $unreadCounts,
        public array $entryCounts,
        public array $flagCounts,
        public UnreadTotalsModel $unreadTotals,
        public TrendingCountsModel $trending,
    ) {
    }
}
