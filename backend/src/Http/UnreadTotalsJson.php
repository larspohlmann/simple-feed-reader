<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Subscription\Model\UnreadTotalsModel;

final class UnreadTotalsJson
{
    /**
     * @return array{
     *   allItems: int,
     *   forYou: int,
     *   tags: list<array{id: int, unreadCount: int}>,
     *   networks: list<array{network: string, unreadCount: int, profilesUnreadCount: int}>,
     *   groups: array{tags: int, feeds: int}
     * }
     */
    public static function from(UnreadTotalsModel $totals): array
    {
        $tags = [];
        foreach ($totals->tags as $id => $unreadCount) {
            $tags[] = ['id' => $id, 'unreadCount' => $unreadCount];
        }
        $networks = [];
        foreach ($totals->networks as $network => $unreadCount) {
            $networks[] = [
                'network' => (string) $network,
                'unreadCount' => $unreadCount,
                'profilesUnreadCount' => $totals->networkProfiles[$network] ?? 0,
            ];
        }

        return [
            'allItems' => $totals->allItems,
            'forYou' => $totals->forYou,
            'tags' => $tags,
            'networks' => $networks,
            'groups' => $totals->feedGroups,
        ];
    }
}
