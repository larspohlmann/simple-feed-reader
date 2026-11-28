<?php

declare(strict_types=1);

namespace App\Service\Library\Subscription;

use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntryShareRepository;
use App\Repository\EntryStateRepository;
use App\Repository\RecommendationItemRepository;
use App\Repository\SocialNotificationRepository;
use App\Repository\SubscriptionEntryCountRepository;
use App\Repository\UnreadCountRepository;
use App\Repository\YourStreamEntryRepository;
use App\Repository\YourStreamSettingsRepository;
use App\Repository\YourStreamWordRepository;
use App\Service\Library\Subscription\Model\SubscriptionTalliesModel;
use App\Service\Library\Subscription\Model\UnreadTotalsModel;
use App\Service\Library\Subscription\Model\YourStreamTallyModel;

final readonly class SubscriptionTallyReader
{
    public function __construct(
        private EntryStateRepository $entryStates,
        private SubscriptionEntryCountRepository $entryCounts,
        private UnreadCountRepository $unreadCounts,
        private RecommendationItemRepository $recommendationItems,
        private EntryShareRepository $entryShares,
        private EntryListRepository $entryList,
        private SocialNotificationRepository $socialNotifications,
        private YourStreamSettingsRepository $streamSettings,
        private YourStreamEntryRepository $streamEntries,
        private YourStreamWordRepository $streamWords,
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

    private function countStream(int $userId): YourStreamTallyModel
    {
        if (!$this->streamSettings->findTuningForUser($userId)->isEnabled()) {
            return YourStreamTallyModel::unavailable();
        }

        $tally = $this->streamEntries->countMembersAndUnread($userId);
        if (0 === $tally->entryCount && !$this->streamWords->hasWordsForUser($userId)) {
            return YourStreamTallyModel::unavailable();
        }

        return $tally;
    }

    /** @return array{favorites: int, kept: int, shared: int, viewed: int, liked: int} */
    private function flagCounts(int $userId): array
    {
        return [
            ...$this->entryStates->stateCountsForUser($userId),
            'shared' => $this->entryShares->countForUser($userId),
            'liked' => $this->entryList->countLikedForUser($userId),
        ];
    }
}
