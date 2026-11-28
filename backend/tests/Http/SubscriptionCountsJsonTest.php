<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Enum\TrendingWindow;
use App\Http\SubscriptionCountsJson;
use App\Service\Curation\Trending\Model\SurfaceTalliesModel;
use App\Service\Curation\Trending\Model\TrendingUnopenedModel;
use App\Service\Library\Subscription\Model\SubscriptionTalliesModel;
use App\Service\Library\Subscription\Model\UnreadTotalsModel;
use App\Service\Library\Subscription\Model\YourStreamTallyModel;
use PHPUnit\Framework\TestCase;

final class SubscriptionCountsJsonTest extends TestCase
{
    public function testMapsUnreadAndEntryCountsAndSurfaceTotals(): void
    {
        $payload = SubscriptionCountsJson::from(new SurfaceTalliesModel(
            new SubscriptionTalliesModel(
                [12 => 3],
                [12 => 40, 8 => 5],
                ['favorites' => 8, 'kept' => 2, 'shared' => 6, 'viewed' => 41, 'liked' => 3],
                new UnreadTotalsModel(7, [3 => 2], ['bluesky' => 5], ['bluesky' => 2], 3, ['tags' => 0, 'feeds' => 0]),
                [],
                YourStreamTallyModel::available(4, 9),
            ),
            new TrendingUnopenedModel(TrendingWindow::Week, 7),
        ));

        self::assertSame([
            'subscriptions' => [
                ['id' => 12, 'unreadCount' => 3, 'entryCount' => 40],
                ['id' => 8, 'unreadCount' => 0, 'entryCount' => 5],
            ],
            'favoritesCount' => 8,
            'keptCount' => 2,
            'sharedCount' => 6,
            'likedCount' => 3,
            'viewedCount' => 41,
            'trending' => ['window' => 'week', 'unopened' => 7],
            'unreadTotals' => [
                'allItems' => 7,
                'forYou' => 3,
                'tags' => [['id' => 3, 'unreadCount' => 2]],
                'networks' => [['network' => 'bluesky', 'unreadCount' => 5, 'profilesUnreadCount' => 2]],
                'groups' => ['tags' => 0, 'feeds' => 0],
            ],
            'notifications' => [],
            'yourStream' => ['available' => true, 'unreadCount' => 4, 'entryCount' => 9],
        ], $payload);
    }

    public function testAnEmptyAccountMapsToEmptySubscriptionsAndZeroTotals(): void
    {
        $payload = SubscriptionCountsJson::from(
            new SurfaceTalliesModel(
                new SubscriptionTalliesModel(
                    [],
                    [],
                    ['favorites' => 0, 'kept' => 0, 'shared' => 0, 'viewed' => 0, 'liked' => 0],
                    new UnreadTotalsModel(0, [], [], [], 0, ['tags' => 0, 'feeds' => 0]),
                    [],
                    YourStreamTallyModel::unavailable(),
                ),
                new TrendingUnopenedModel(TrendingWindow::Day, 0),
            ),
        );

        self::assertSame([], $payload['subscriptions']);
        self::assertSame(0, $payload['favoritesCount']);
        self::assertSame(['window' => 'day', 'unopened' => 0], $payload['trending']);
        self::assertSame(0, $payload['unreadTotals']['allItems']);
        self::assertSame(0, $payload['unreadTotals']['forYou']);
        self::assertSame(['available' => false, 'unreadCount' => 0, 'entryCount' => 0], $payload['yourStream']);
    }

    public function testCarriesNotificationCounts(): void
    {
        $payload = SubscriptionCountsJson::from(new SurfaceTalliesModel(
            new SubscriptionTalliesModel(
                [],
                [],
                ['favorites' => 0, 'kept' => 0, 'shared' => 0, 'viewed' => 0, 'liked' => 0],
                new UnreadTotalsModel(0, [], [], [], 0, ['tags' => 0, 'feeds' => 0]),
                ['mastodon' => 2, 'bluesky' => 1],
                YourStreamTallyModel::unavailable(),
            ),
            new TrendingUnopenedModel(TrendingWindow::Day, 0),
        ));

        self::assertSame([
            ['network' => 'bluesky', 'unseenCount' => 1],
            ['network' => 'mastodon', 'unseenCount' => 2],
        ], $payload['notifications']);
    }
}
