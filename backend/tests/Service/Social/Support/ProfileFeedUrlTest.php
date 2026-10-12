<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Support\ProfileFeedUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileFeedUrlTest extends TestCase
{
    public function testABlueskyProfileFeedIsBuiltFromTheDid(): void
    {
        self::assertSame('https://bsky.app/profile/did:plc:bob/rss', ProfileFeedUrl::bluesky('did:plc:bob'));
    }

    public function testAMastodonProfileFeedIsBuiltFromTheUserAndDomain(): void
    {
        self::assertSame('https://example.social/@bob.rss', ProfileFeedUrl::mastodon('bob', 'example.social'));
    }

    public function testOnlyAnIdentityStartingWithDidIsADid(): void
    {
        self::assertTrue(ProfileFeedUrl::isDid('did:plc:bob'));
        self::assertTrue(ProfileFeedUrl::isDid('did:web:example.com'));
        self::assertFalse(ProfileFeedUrl::isDid('bob.bsky.social'));
        self::assertFalse(ProfileFeedUrl::isDid('xdid:plc:bob'));
        self::assertFalse(ProfileFeedUrl::isDid('DID:plc:bob'));
    }

    /** @return iterable<string, array{SocialNetwork, string, ?string}> */
    public static function urls(): iterable
    {
        yield 'bluesky by did' => [SocialNetwork::Bluesky, 'https://bsky.app/profile/did:plc:bob/rss', 'did:plc:bob'];
        yield 'bluesky over http, upper-case host, trailing slash' => [
            SocialNetwork::Bluesky,
            'HTTP://BSKY.APP/profile/did:plc:bob/rss/',
            'did:plc:bob',
        ];
        yield 'bluesky encoded did' => [
            SocialNetwork::Bluesky,
            'https://bsky.app/profile/did%3Aplc%3Abob/rss',
            'did:plc:bob',
        ];
        yield 'bluesky handle, lower-cased' => [
            SocialNetwork::Bluesky,
            'https://bsky.app/profile/Bob.Example.com/rss',
            'bob.example.com',
        ];
        yield 'bluesky profile page, not its feed' => [
            SocialNetwork::Bluesky,
            'https://bsky.app/profile/did:plc:bob',
            null,
        ];
        yield 'bluesky path on another host' => [
            SocialNetwork::Bluesky,
            'https://example.com/profile/did:plc:bob/rss',
            null,
        ];
        yield 'bluesky over ftp' => [SocialNetwork::Bluesky, 'ftp://bsky.app/profile/did:plc:bob/rss', null];
        yield 'mastodon, host lower-cased' => [
            SocialNetwork::Mastodon,
            'https://Mastodon.Example/@bob.rss',
            'bob@mastodon.example',
        ];
        yield 'mastodon profile page, not its feed' => [SocialNetwork::Mastodon, 'https://mastodon.example/@bob', null];
        yield 'mastodon remote form' => [
            SocialNetwork::Mastodon,
            'https://mastodon.example/@bob@other.example.rss',
            null,
        ];
        yield 'mastodon users path' => [SocialNetwork::Mastodon, 'https://mastodon.example/users/bob.rss', null];
        yield 'a bluesky feed read as mastodon' => [
            SocialNetwork::Mastodon,
            'https://bsky.app/profile/did:plc:bob/rss',
            null,
        ];
        yield 'no host' => [SocialNetwork::Mastodon, 'https:///@bob.rss', null];
        yield 'a web scheme without a host' => [SocialNetwork::Mastodon, 'https:/@bob.rss', null];
    }

    #[DataProvider('urls')]
    public function testAUrlReadsAsTheIdentityItFollows(SocialNetwork $network, string $url, ?string $identity): void
    {
        self::assertSame($identity, ProfileFeedUrl::identityOf($network, $url));
    }
}
