<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Enum\SocialNetwork;
use App\Http\SocialNetworksJson;
use PHPUnit\Framework\TestCase;

final class SocialNetworksJsonTest extends TestCase
{
    public function testEachNetworkBecomesItsWireValueInOrder(): void
    {
        self::assertSame(
            ['bluesky', 'mastodon'],
            SocialNetworksJson::values([SocialNetwork::Bluesky, SocialNetwork::Mastodon]),
        );
    }
}
