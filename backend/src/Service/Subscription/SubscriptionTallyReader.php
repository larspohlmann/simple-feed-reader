<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Repository\EntryStateRepository;
use App\Repository\SubscriptionEntryCountRepository;
use App\Service\Subscription\Model\SubscriptionTalliesModel;

final readonly class SubscriptionTallyReader
{
    public function __construct(
        private EntryStateRepository $entryStates,
        private SubscriptionEntryCountRepository $entryCounts,
    ) {
    }

    public function forUser(int $userId): SubscriptionTalliesModel
    {
        return new SubscriptionTalliesModel(
            $this->entryStates->unreadCountsForUser($userId),
            $this->entryCounts->forUser($userId),
            $this->entryStates->stateCountsForUser($userId),
        );
    }
}
