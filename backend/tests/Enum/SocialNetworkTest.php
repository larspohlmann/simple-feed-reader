<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\SocialNetwork;
use PHPUnit\Framework\TestCase;

final class SocialNetworkTest extends TestCase
{
    public function testEachNetworkHasItsDisplayName(): void
    {
        self::assertSame('Bluesky', SocialNetwork::Bluesky->displayName());
        self::assertSame('Mastodon', SocialNetwork::Mastodon->displayName());
    }
}
