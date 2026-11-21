<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\CanonicalItemKind;
use App\Enum\SocialNetwork;
use PHPUnit\Framework\TestCase;

final class CanonicalItemKindTest extends TestCase
{
    public function testEachNetworkHasItsKind(): void
    {
        self::assertSame(CanonicalItemKind::Bluesky, CanonicalItemKind::fromNetwork(SocialNetwork::Bluesky));
        self::assertSame(CanonicalItemKind::Mastodon, CanonicalItemKind::fromNetwork(SocialNetwork::Mastodon));
        self::assertSame(CanonicalItemKind::Lemmy, CanonicalItemKind::fromNetwork(SocialNetwork::Lemmy));
    }
}
