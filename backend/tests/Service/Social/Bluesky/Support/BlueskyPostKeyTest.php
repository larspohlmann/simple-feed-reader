<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Bluesky\Support;

use App\Service\Social\Bluesky\Support\BlueskyPostKey;
use PHPUnit\Framework\TestCase;

final class BlueskyPostKeyTest extends TestCase
{
    public function testAtUriIsItsOwnKey(): void
    {
        $uri = 'at://did:plc:abc/app.bsky.feed.post/3k';

        self::assertSame($uri, BlueskyPostKey::parseKey($uri)?->key);
        self::assertNull(BlueskyPostKey::parseKey('https://bsky.app/profile/x/post/3k'));
        self::assertNull(BlueskyPostKey::parseKey(null));
    }
}
