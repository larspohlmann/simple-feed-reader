<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\UnreadTotalsJson;
use App\Service\Subscription\Model\UnreadTotalsModel;
use PHPUnit\Framework\TestCase;

final class UnreadTotalsJsonTest extends TestCase
{
    public function testListsEveryNetworkWithItsProfileCountDefaultingToZero(): void
    {
        $payload = UnreadTotalsJson::from(new UnreadTotalsModel(
            allItems: 7,
            tags: [3 => 2],
            networks: ['bluesky' => 5, 'mastodon' => 1],
            networkProfiles: ['bluesky' => 2],
        ));

        self::assertSame([
            'allItems' => 7,
            'tags' => [['id' => 3, 'unreadCount' => 2]],
            'networks' => [
                ['network' => 'bluesky', 'unreadCount' => 5, 'profilesUnreadCount' => 2],
                ['network' => 'mastodon', 'unreadCount' => 1, 'profilesUnreadCount' => 0],
            ],
        ], $payload);
    }
}
