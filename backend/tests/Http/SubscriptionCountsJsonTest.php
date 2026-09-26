<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\SubscriptionCountsJson;
use App\Service\Subscription\SubscriptionTallies;
use PHPUnit\Framework\TestCase;

final class SubscriptionCountsJsonTest extends TestCase
{
    public function testMapsUnreadAndEntryCountsAndSurfaceTotals(): void
    {
        $payload = SubscriptionCountsJson::from(new SubscriptionTallies(
            [12 => 3],
            [12 => 40, 8 => 5],
            ['favorites' => 8, 'kept' => 2, 'viewed' => 41],
        ));

        self::assertSame([
            'subscriptions' => [
                ['id' => 12, 'unreadCount' => 3, 'entryCount' => 40],
                ['id' => 8, 'unreadCount' => 0, 'entryCount' => 5],
            ],
            'favoritesCount' => 8,
            'keptCount' => 2,
            'viewedCount' => 41,
        ], $payload);
    }

    public function testAnEmptyAccountMapsToEmptySubscriptionsAndZeroTotals(): void
    {
        $payload = SubscriptionCountsJson::from(
            new SubscriptionTallies([], [], ['favorites' => 0, 'kept' => 0, 'viewed' => 0]),
        );

        self::assertSame([], $payload['subscriptions']);
        self::assertSame(0, $payload['favoritesCount']);
    }
}
