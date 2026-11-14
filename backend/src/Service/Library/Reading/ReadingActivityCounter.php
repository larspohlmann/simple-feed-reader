<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\User;
use App\Repository\EntryStateRepository;
use App\Service\Reading\Model\ReadingActivityModel;
use App\Service\Reading\Model\ReadingTotalsModel;
use App\Service\SubscriptionHistory\SubscriptionActivityCounter;

final readonly class ReadingActivityCounter
{
    private const int TOP_FEEDS = 5;

    public function __construct(
        private SubscriptionActivityCounter $daily,
        private EntryStateRepository $states,
    ) {
    }

    public function activity(User $user): ReadingActivityModel
    {
        $states = $this->states->plainFeedStateCountsForUser($user->requireId());

        return new ReadingActivityModel(
            $this->daily->lastDays($user),
            $this->states->readCountsByFeed($user->requireId(), self::TOP_FEEDS),
            new ReadingTotalsModel($states['viewed'], $states['favorites']),
        );
    }
}
