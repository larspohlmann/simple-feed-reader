<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Subscription\Model\SubscriptionTalliesModel;

/**
 * The sidebar poll's payload: each subscription's counts and the surface totals, nothing else. A subscription
 * missing from the list has no entries; the client counts it as zero.
 */
final class SubscriptionCountsJson
{
    /**
     * @return array{
     *   subscriptions: list<array{id: int, unreadCount: int, entryCount: int}>,
     *   favoritesCount: int, keptCount: int, sharedCount: int, viewedCount: int,
     *   trending: array{day: int, week: int},
     *   unreadTotals: array{
     *     allItems: int,
     *     forYou: int,
     *     tags: list<array{id: int, unreadCount: int}>,
     *     networks: list<array{network: string, unreadCount: int, profilesUnreadCount: int}>
     *   }
     * }
     */
    public static function from(SubscriptionTalliesModel $tallies): array
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

    /**
     * @return array{
     *   favoritesCount: int, keptCount: int, sharedCount: int, viewedCount: int,
     *   trending: array{day: int, week: int},
     *   unreadTotals: array{
     *     allItems: int,
     *     forYou: int,
     *     tags: list<array{id: int, unreadCount: int}>,
     *     networks: list<array{network: string, unreadCount: int, profilesUnreadCount: int}>
     *   }
     * }
     */
    public static function surfaceTotals(SubscriptionTalliesModel $tallies): array
    {
        return [
            'favoritesCount' => $tallies->flagCounts['favorites'],
            'keptCount' => $tallies->flagCounts['kept'],
            'sharedCount' => $tallies->flagCounts['shared'],
            'viewedCount' => $tallies->flagCounts['viewed'],
            'trending' => ['day' => $tallies->trending->day, 'week' => $tallies->trending->week],
            'unreadTotals' => UnreadTotalsJson::from($tallies->unreadTotals),
        ];
    }
}
