<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The sidebar poll's cheap payload (#720): every subscription's unread count
 * plus the three surface totals, and nothing else. It replaces the 137 KB
 * bootstrap on a tick that only needs the numbers — no feeds, no tags, no
 * descriptions. A subscription absent from the list has no entries; the
 * client defaults it to zero against the list it already holds.
 */
final class SubscriptionCountsJson
{
    /**
     * @param array<int, int>                               $unreadCounts subscription id => unread count
     * @param array<int, int>                               $entryCounts  subscription id => entry count
     * @param array{favorites: int, kept: int, viewed: int} $flags
     *
     * @return array{
     *   subscriptions: list<array{id: int, unreadCount: int, entryCount: int}>,
     *   favoritesCount: int, keptCount: int, viewedCount: int
     * }
     */
    public static function from(array $unreadCounts, array $entryCounts, array $flags): array
    {
        $subscriptions = [];
        foreach ($entryCounts as $id => $entryCount) {
            $subscriptions[] = ['id' => $id, 'unreadCount' => $unreadCounts[$id] ?? 0, 'entryCount' => $entryCount];
        }

        return [
            'subscriptions' => $subscriptions,
            'favoritesCount' => $flags['favorites'],
            'keptCount' => $flags['kept'],
            'viewedCount' => $flags['viewed'],
        ];
    }
}
