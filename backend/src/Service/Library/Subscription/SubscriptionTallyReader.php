<?php

declare(strict_types=1);

namespace App\Service\Library\Subscription;

use App\Entity\User;
use App\Repository\EntryShareRepository;
use App\Repository\EntryStateRepository;
use App\Repository\ForYouStreamEntryRepository;
use App\Repository\InterestTermSetRepository;
use App\Repository\RecommendationItemRepository;
use App\Repository\SocialNotificationRepository;
use App\Repository\SubscriptionEntryCountRepository;
use App\Repository\UnreadCountRepository;
use App\Service\Library\Subscription\Model\ForYouStreamTallyModel;
use App\Service\Library\Subscription\Model\SubscriptionTalliesModel;
use App\Service\Library\Subscription\Model\UnreadTotalsModel;

final readonly class SubscriptionTallyReader
{
    public function __construct(
        private EntryStateRepository $entryStates,
        private SubscriptionEntryCountRepository $entryCounts,
        private UnreadCountRepository $unreadCounts,
        private RecommendationItemRepository $recommendationItems,
        private EntryShareRepository $entryShares,
        private SocialNotificationRepository $socialNotifications,
        private InterestTermSetRepository $interestTermSets,
        private ForYouStreamEntryRepository $streamEntries,
    ) {
    }

    public function countTallies(User $user): SubscriptionTalliesModel
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
                $this->unreadCounts->perNetwork($userId, $perSubscription),
                $this->unreadCounts->perNetworkProfiles($userId, $perSubscription),
                $this->recommendationItems->countForYou($userId),
                $this->unreadCounts->perFeedGroup($userId, $perSubscription),
            ),
            $this->socialNotifications->countUnseenGroups($user),
            $this->countStream($userId),
        );
    }

    private function countStream(int $userId): ForYouStreamTallyModel
    {
        if (!$this->interestTermSets->hasForUser($userId)) {
            return ForYouStreamTallyModel::unavailable();
        }

        return new ForYouStreamTallyModel(
            true,
            $this->streamEntries->countUnreadMembers($userId),
            $this->streamEntries->countMembers($userId),
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
