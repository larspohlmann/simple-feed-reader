<?php

declare(strict_types=1);

namespace App\Service\Library\Subscription\Model;

final readonly class SubscriptionTalliesModel
{
    /**
     * @param array<int, int> $unreadCounts subscription id => unread
     * @param array<int, int> $entryCounts subscription id => entries
     * @param array{favorites: int, kept: int, shared: int, viewed: int, liked: int} $flagCounts
     * @param array<string, int> $notificationsUnseen network => unseen groups
     */
    public function __construct(
        public array $unreadCounts,
        public array $entryCounts,
        public array $flagCounts,
        public UnreadTotalsModel $unreadTotals,
        public array $notificationsUnseen,
        public ForYouStreamTallyModel $forYouStream,
    ) {
    }
}
