<?php

declare(strict_types=1);

namespace App\Service\Library\Reading;

use App\Entity\User;
use App\Repository\EntryStateRepository;
use App\Service\Library\Reading\Model\ReadingActivityModel;
use App\Service\Library\Reading\Model\ReadingTotalsModel;
use App\Service\Library\SubscriptionHistory\SubscriptionActivityCounter;

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
