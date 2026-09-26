<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Subscription\SubscriptionTallies;

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
     * @return array{
     *   subscriptions: list<array{id: int, unreadCount: int, entryCount: int}>,
     *   favoritesCount: int, keptCount: int, viewedCount: int
     * }
     */
    public static function from(SubscriptionTallies $tallies): array
    {
        $subscriptions = [];
        foreach ($tallies->entryCounts as $id => $entryCount) {
            $subscriptions[] = [
                'id' => $id,
                'unreadCount' => $tallies->unreadCounts[$id] ?? 0,
                'entryCount' => $entryCount,
            ];
        }

        return ['subscriptions' => $subscriptions, ...self::surfaceTotals($tallies)];
    }

    /** @return array{favoritesCount: int, keptCount: int, viewedCount: int} */
    public static function surfaceTotals(SubscriptionTallies $tallies): array
    {
        return [
            'favoritesCount' => $tallies->flags['favorites'],
            'keptCount' => $tallies->flags['kept'],
            'viewedCount' => $tallies->flags['viewed'],
        ];
    }
}
