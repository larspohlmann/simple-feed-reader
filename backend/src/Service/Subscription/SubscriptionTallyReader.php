<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Repository\EntryStateRepository;
use App\Repository\SubscriptionEntryCountRepository;
use App\Repository\UnreadCountRepository;
use App\Service\Subscription\Model\SubscriptionTalliesModel;
use App\Service\Subscription\Model\UnreadTotalsModel;

final readonly class SubscriptionTallyReader
{
    public function __construct(
        private EntryStateRepository $entryStates,
        private SubscriptionEntryCountRepository $entryCounts,
        private UnreadCountRepository $unreadCounts,
    ) {
    }

    public function forUser(int $userId): SubscriptionTalliesModel
    {
        return new SubscriptionTalliesModel(
            $this->unreadCounts->perSubscription($userId),
            $this->entryCounts->forUser($userId),
            $this->entryStates->stateCountsForUser($userId),
            new UnreadTotalsModel(
                $this->unreadCounts->allItems($userId),
                $this->unreadCounts->perTag($userId),
                $this->unreadCounts->perNetwork($userId),
                $this->unreadCounts->perNetworkProfiles($userId),
            ),
        );
    }
}
