<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Repository\EntryShareRepository;
use App\Repository\EntryStateRepository;
use App\Repository\RecommendationItemRepository;
use App\Repository\SubscriptionEntryCountRepository;
use App\Repository\UnreadCountRepository;
use App\Service\Subscription\Model\SubscriptionTalliesModel;
use App\Service\Subscription\Model\UnreadTotalsModel;
use App\Service\Trending\TrendingStories;

final readonly class SubscriptionTallyReader
{
    public function __construct(
        private EntryStateRepository $entryStates,
        private SubscriptionEntryCountRepository $entryCounts,
        private UnreadCountRepository $unreadCounts,
        private RecommendationItemRepository $recommendationItems,
        private EntryShareRepository $entryShares,
        private TrendingStories $trendingStories,
    ) {
    }

    public function forUser(User $user, TrendingWindow $trendingWindow): SubscriptionTalliesModel
    {
        $userId = $user->requireId();
        $perSubscription = $this->unreadCounts->perSubscription($userId);

        return new SubscriptionTalliesModel(
            $perSubscription,
            $this->entryCounts->forUser($userId),
            $this->flagCounts($userId),
            new UnreadTotalsModel(
                $this->unreadCounts->allItems($userId, $perSubscription),
                $this->unreadCounts->perTag($userId, $perSubscription),
                $this->unreadCounts->perNetwork($userId),
                $this->unreadCounts->perNetworkProfiles($userId),
                $this->recommendationItems->countForYou($userId),
                $this->unreadCounts->perFeedGroup($userId, $perSubscription),
            ),
            $this->trendingStories->unopenedFor($user, $trendingWindow),
        );
    }

    /** @return array{favorites: int, kept: int, shared: int, viewed: int} */
    private function flagCounts(int $userId): array
    {
        return [
            ...$this->entryStates->stateCountsForUser($userId),
            'shared' => $this->entryShares->countForUser($userId),
        ];
    }
}
