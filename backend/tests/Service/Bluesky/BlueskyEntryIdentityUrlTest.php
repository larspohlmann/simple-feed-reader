<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky;

use App\Service\Bluesky\BlueskyEntryIdentityUrl;
use PHPUnit\Framework\TestCase;

final class BlueskyEntryIdentityUrlTest extends TestCase
{
    public function testAPostUriNamesItsDidFormWebUrl(): void
    {
        self::assertSame(
            'https://bsky.app/profile/did:plc:abc/post/3mz7',
            (new BlueskyEntryIdentityUrl())->forGuid('at://did:plc:abc/app.bsky.feed.post/3mz7'),
        );
    }

    public function testAnyOtherGuidNamesNone(): void
    {
        self::assertNull((new BlueskyEntryIdentityUrl())->forGuid('https://example.com/story'));
    }
}
