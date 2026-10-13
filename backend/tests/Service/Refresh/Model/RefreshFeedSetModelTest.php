<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Model;

use App\Enum\SocialNetwork;
use App\Service\Refresh\Model\RefreshFeedSetModel;
use PHPUnit\Framework\TestCase;

final class RefreshFeedSetModelTest extends TestCase
{
    public function testANetworksSetIsKeyedByTheNetwork(): void
    {
        self::assertSame('network-bluesky', (new RefreshFeedSetModel(SocialNetwork::Bluesky))->key());
        self::assertSame('network-mastodon', (new RefreshFeedSetModel(SocialNetwork::Mastodon))->key());
    }
}
