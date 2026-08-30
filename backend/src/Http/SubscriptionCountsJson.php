<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The sidebar poll's cheap payload (#720): every subscription's unread count
 * plus the three surface totals, and nothing else. It replaces the 137 KB
 * bootstrap on a tick that only needs the numbers — no feeds, no tags, no
 * descriptions. A subscription absent from `$unreadCounts` has no unread
 * entries; the client defaults it to zero against the list it already holds.
 */
final class SubscriptionCountsJson
{
    /**
     * @param array<int, int>                              $unreadCounts subscription id => unread count
     * @param array{favorites: int, kept: int, viewed: int} $flags
     *
     * @return array{
     *   subscriptions: list<array{id: int, unreadCount: int}>,
     *   favoritesCount: int, keptCount: int, viewedCount: int
     * }
     */
    public static function from(array $unreadCounts, array $flags): array
    {
        $subscriptions = [];
        foreach ($unreadCounts as $id => $unreadCount) {
            $subscriptions[] = ['id' => $id, 'unreadCount' => $unreadCount];
        }

        return [
            'subscriptions' => $subscriptions,
            'favoritesCount' => $flags['favorites'],
            'keptCount' => $flags['kept'],
            'viewedCount' => $flags['viewed'],
        ];
    }
}
