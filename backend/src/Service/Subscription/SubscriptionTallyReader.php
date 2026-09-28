<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Repository\EntryStateRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Subscription\Model\SubscriptionTalliesModel;

final readonly class SubscriptionTallyReader
{
    public function __construct(
        private EntryStateRepository $entryStates,
        private SubscriptionRepository $subscriptions,
    ) {
    }

    public function forUser(int $userId): SubscriptionTalliesModel
    {
        return new SubscriptionTalliesModel(
            $this->entryStates->unreadCountsForUser($userId),
            $this->subscriptions->entryCountsForUser($userId),
            $this->entryStates->stateCountsForUser($userId),
        );
    }
}
