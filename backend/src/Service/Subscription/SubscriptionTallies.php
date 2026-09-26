<?php

declare(strict_types=1);

namespace App\Service\Subscription;

final readonly class SubscriptionTallies
{
    /**
     * @param array<int, int>                               $unreadCounts subscription id => unread count, 0 absent
     * @param array<int, int>                               $entryCounts  subscription id => entries, read or not
     * @param array{favorites: int, kept: int, viewed: int} $flags
     */
    public function __construct(
        public array $unreadCounts,
        public array $entryCounts,
        public array $flags,
    ) {
    }
}
