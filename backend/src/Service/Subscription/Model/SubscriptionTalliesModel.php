<?php

declare(strict_types=1);

namespace App\Service\Subscription\Model;

final readonly class SubscriptionTalliesModel
{
    /**
     * @param array<int, int>                               $unreadCounts subscription id => unread count, 0 absent
     * @param array<int, int>                               $entryCounts  subscription id => entries, read or not
     * @param array{favorites: int, kept: int, viewed: int} $flagCounts
     */
    public function __construct(
        public array $unreadCounts,
        public array $entryCounts,
        public array $flagCounts,
    ) {
    }
}
