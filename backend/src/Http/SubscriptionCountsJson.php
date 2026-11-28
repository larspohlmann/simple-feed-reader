<?php

declare(strict_types=1);

namespace App\Http;

use App\Enum\SocialNetwork;
use App\Service\Curation\Trending\Model\SurfaceTalliesModel;

/**
 * The sidebar poll's payload: each subscription's counts and the surface totals, nothing else. A subscription
 * missing from the list has no entries; the client counts it as zero.
 */
final class SubscriptionCountsJson
{
    /**
     * @return array{
     *   subscriptions: list<array{id: int, unreadCount: int, entryCount: int}>,
     *   favoritesCount: int, keptCount: int, sharedCount: int, likedCount: int, viewedCount: int,
     *   trending: array{window: string, unopened: int},
     *   notifications: list<array{network: string, unseenCount: int}>,
     *   yourStream: array{available: bool, unreadCount: int, entryCount: int},
     *   unreadTotals: array{
     *     allItems: int,
     *     forYou: int,
     *     tags: list<array{id: int, unreadCount: int}>,
     *     networks: list<array{network: string, unreadCount: int, profilesUnreadCount: int}>
     *   }
     * }
     */
    public static function from(SurfaceTalliesModel $tallies): array
    {
        $subscriptions = [];
        foreach ($tallies->subscriptions->entryCounts as $id => $entryCount) {
            $subscriptions[] = [
                'id' => $id,
                'unreadCount' => $tallies->subscriptions->unreadCounts[$id] ?? 0,
                'entryCount' => $entryCount,
            ];
        }

        return ['subscriptions' => $subscriptions, ...self::surfaceTotals($tallies)];
    }

    /**
     * @return array{
     *   favoritesCount: int, keptCount: int, sharedCount: int, likedCount: int, viewedCount: int,
     *   trending: array{window: string, unopened: int},
     *   notifications: list<array{network: string, unseenCount: int}>,
     *   yourStream: array{available: bool, unreadCount: int, entryCount: int},
     *   unreadTotals: array{
     *     allItems: int,
     *     forYou: int,
     *     tags: list<array{id: int, unreadCount: int}>,
     *     networks: list<array{network: string, unreadCount: int, profilesUnreadCount: int}>
     *   }
     * }
     */
    public static function surfaceTotals(SurfaceTalliesModel $tallies): array
    {
        return [
            'favoritesCount' => $tallies->subscriptions->flagCounts['favorites'],
            'keptCount' => $tallies->subscriptions->flagCounts['kept'],
            'sharedCount' => $tallies->subscriptions->flagCounts['shared'],
            'likedCount' => $tallies->subscriptions->flagCounts['liked'],
            'viewedCount' => $tallies->subscriptions->flagCounts['viewed'],
            'trending' => TrendingUnopenedJson::from($tallies->trending),
            'unreadTotals' => UnreadTotalsJson::from($tallies->subscriptions->unreadTotals),
            'notifications' => self::notificationCounts($tallies->subscriptions->notificationsUnseen),
            'yourStream' => [
                'available' => $tallies->subscriptions->yourStream->available,
                'unreadCount' => $tallies->subscriptions->yourStream->unreadCount,
                'entryCount' => $tallies->subscriptions->yourStream->entryCount,
            ],
        ];
    }

    /**
     * @param array<string, int> $unseenByNetwork
     * @return list<array{network: string, unseenCount: int}>
     */
    private static function notificationCounts(array $unseenByNetwork): array
    {
        $counts = [];
        foreach (SocialNetwork::cases() as $network) {
            if (isset($unseenByNetwork[$network->value])) {
                $counts[] = ['network' => $network->value, 'unseenCount' => $unseenByNetwork[$network->value]];
            }
        }

        return $counts;
    }
}
