<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky\Support;

use App\Service\Bluesky\Support\BlueskyActorKey;
use PHPUnit\Framework\TestCase;

final class BlueskyActorKeyTest extends TestCase
{
    public function testAHandleIsKeyedLowerCased(): void
    {
        self::assertSame('rusty.bsky.social', BlueskyActorKey::of('Rusty.Bsky.Social'));
    }

    public function testADidIsKeyedAsItIs(): void
    {
        self::assertSame('did:plc:HczfD45', BlueskyActorKey::of('did:plc:HczfD45'));
    }

    public function testOnlyAnActorStartingWithTheDidSchemeIsADid(): void
    {
        self::assertTrue(BlueskyActorKey::isDid('did:web:example.com'));
        self::assertTrue(BlueskyActorKey::isDid('did:plc:bob'));
        self::assertFalse(BlueskyActorKey::isDid('did.bsky.social'));
        self::assertFalse(BlueskyActorKey::isDid('DID:plc:bob'));
        self::assertFalse(BlueskyActorKey::isDid('x-did:plc:abc'));
    }
}
