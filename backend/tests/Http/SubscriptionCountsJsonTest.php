<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\SubscriptionCountsJson;
use App\Service\Subscription\Model\SubscriptionTalliesModel;
use App\Service\Subscription\Model\UnreadTotalsModel;
use PHPUnit\Framework\TestCase;

final class SubscriptionCountsJsonTest extends TestCase
{
    public function testMapsUnreadAndEntryCountsAndSurfaceTotals(): void
    {
        $payload = SubscriptionCountsJson::from(new SubscriptionTalliesModel(
            [12 => 3],
            [12 => 40, 8 => 5],
            ['favorites' => 8, 'kept' => 2, 'viewed' => 41],
            new UnreadTotalsModel(7, [3 => 2], ['bluesky' => 5], ['bluesky' => 2]),
        ));

        self::assertSame([
            'subscriptions' => [
                ['id' => 12, 'unreadCount' => 3, 'entryCount' => 40],
                ['id' => 8, 'unreadCount' => 0, 'entryCount' => 5],
            ],
            'favoritesCount' => 8,
            'keptCount' => 2,
            'viewedCount' => 41,
            'unreadTotals' => [
                'allItems' => 7,
                'tags' => [['id' => 3, 'unreadCount' => 2]],
                'networks' => [['network' => 'bluesky', 'unreadCount' => 5, 'profilesUnreadCount' => 2]],
            ],
        ], $payload);
    }

    public function testAnEmptyAccountMapsToEmptySubscriptionsAndZeroTotals(): void
    {
        $payload = SubscriptionCountsJson::from(
            new SubscriptionTalliesModel(
                [],
                [],
                ['favorites' => 0, 'kept' => 0, 'viewed' => 0],
                new UnreadTotalsModel(0, [], [], []),
            ),
        );

        self::assertSame([], $payload['subscriptions']);
        self::assertSame(0, $payload['favoritesCount']);
        self::assertSame(0, $payload['unreadTotals']['allItems']);
    }
}
