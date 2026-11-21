<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Mastodon\Support;

use App\Service\Social\Mastodon\Support\MastodonStatusKey;
use PHPUnit\Framework\TestCase;

final class MastodonStatusKeyTest extends TestCase
{
    public function testStatusPageAndUriShareOneKey(): void
    {
        $page = MastodonStatusKey::parseKey('https://mastodon.example/@alice/123');
        $uri = MastodonStatusKey::parseKey('https://mastodon.example/users/alice/statuses/123');

        self::assertSame('https://mastodon.example/users/alice/statuses/123', $page?->key);
        self::assertNotNull($uri);
        self::assertSame($uri->keyHash(), $page->keyHash());
    }

    public function testNonMastodonUriKeepsItsAbsoluteAddress(): void
    {
        $address = 'https://pl.example/objects/9f1c';

        self::assertSame($address, MastodonStatusKey::parseKey($address)?->key);
    }

    public function testNoAddressIsNoKey(): void
    {
        self::assertNull(MastodonStatusKey::parseKey(null));
        self::assertNull(MastodonStatusKey::parseKey('not a url'));
    }

    public function testEntryKeyPrefersAStatusAddressOverAnEarlierAbsoluteOne(): void
    {
        $status = 'https://mastodon.example/@alice/123';
        $key = MastodonStatusKey::parseEntryKey('https://pl.example/objects/9f1c', $status);

        self::assertSame('https://mastodon.example/users/alice/statuses/123', $key?->key);
    }

    public function testEntryKeyFallsBackToTheFirstAbsoluteAddress(): void
    {
        self::assertSame(
            'https://pl.example/objects/9f1c',
            MastodonStatusKey::parseEntryKey('https://pl.example/objects/9f1c', 'https://pl.example/other')?->key,
        );
        self::assertSame(
            'https://pl.example/other',
            MastodonStatusKey::parseEntryKey(null, 'https://pl.example/other')?->key,
        );
        self::assertNull(MastodonStatusKey::parseEntryKey('not a url', null));
    }
}
