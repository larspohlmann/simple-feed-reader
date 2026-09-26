<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Repository\EntryStateRepository;
use App\Repository\SubscriptionRepository;

final readonly class SubscriptionTallyReader
{
    public function __construct(
        private EntryStateRepository $entryStates,
        private SubscriptionRepository $subscriptions,
    ) {
    }

    public function forUser(int $userId): SubscriptionTallies
    {
        return new SubscriptionTallies(
            $this->entryStates->unreadCountsForUser($userId),
            $this->subscriptions->entryCountsForUser($userId),
            $this->entryStates->stateCountsForUser($userId),
        );
    }
}
