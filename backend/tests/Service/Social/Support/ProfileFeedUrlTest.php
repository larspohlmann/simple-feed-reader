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
        yield 'bluesky actor carrying an @' => [
            SocialNetwork::Bluesky,
            'https://bsky.app/profile/bob@x.example/rss',
            null,
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

    public function testNetworkOfNamesTheNetworkWhoseProfileFeedTheUrlIs(): void
    {
        $bluesky = ProfileFeedUrl::networkOf('https://bsky.app/profile/bob.bsky.social/rss');
        self::assertSame(SocialNetwork::Bluesky, $bluesky);
        self::assertSame(SocialNetwork::Mastodon, ProfileFeedUrl::networkOf('https://mastodon.example/@bob.rss'));
        self::assertNull(ProfileFeedUrl::networkOf('https://example.com/feed.xml'));
    }

    /** @return iterable<string, array{SocialNetwork, string, ?string}> */
    public static function identities(): iterable
    {
        yield 'a Bluesky DID' => [SocialNetwork::Bluesky, 'did:plc:bob', 'https://bsky.app/profile/did:plc:bob/rss'];
        yield 'a Bluesky handle' => [
            SocialNetwork::Bluesky,
            'Bob.bsky.social',
            'https://bsky.app/profile/Bob.bsky.social/rss',
        ];
        yield 'a Mastodon account' => [
            SocialNetwork::Mastodon,
            'bob@mastodon.example',
            'https://mastodon.example/@bob.rss',
        ];
        yield 'a Mastodon account on Bluesky' => [SocialNetwork::Bluesky, 'bob@mastodon.example', null];
        yield 'a DID on Mastodon' => [SocialNetwork::Mastodon, 'did:plc:bob', null];
        yield 'a Bluesky path' => [SocialNetwork::Bluesky, 'did:plc:bob/rss', null];
        yield 'a Bluesky query' => [SocialNetwork::Bluesky, 'bob?x=1', null];
        yield 'an empty identity' => [SocialNetwork::Bluesky, '', null];
        yield 'a Mastodon user without a server' => [SocialNetwork::Mastodon, 'bob', null];
        yield 'a Mastodon server without a user' => [SocialNetwork::Mastodon, '@mastodon.example', null];
        yield 'a Mastodon account with two servers' => [SocialNetwork::Mastodon, 'bob@a.example@b.example', null];
        yield 'a Mastodon server with a path' => [SocialNetwork::Mastodon, 'bob@mastodon.example/x', null];
        yield 'a Mastodon server with a port' => [SocialNetwork::Mastodon, 'bob@mastodon.example:8080', null];
    }

    #[DataProvider('identities')]
    public function testAnIdentityBuildsItsProfileFeedOnlyWhenItNamesAnAccountOfTheNetwork(
        SocialNetwork $network,
        string $identity,
        ?string $feedUrl,
    ): void {
        self::assertSame($feedUrl, ProfileFeedUrl::of($network, $identity));
    }

    #[DataProvider('profilePages')]
    public function testABlueskyProfilePageOrItsFeedNamesItsActor(string $url, ?string $actor): void
    {
        self::assertSame($actor, ProfileFeedUrl::blueskyProfileActorOf($url));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function profilePages(): iterable
    {
        yield 'a page by handle' => ['https://bsky.app/profile/Rusty-17.bsky.social', 'rusty-17.bsky.social'];
        yield 'its feed' => ['https://bsky.app/profile/rusty-17.bsky.social/rss', 'rusty-17.bsky.social'];
        yield 'a trailing slash' => ['https://bsky.app/profile/rusty-17.bsky.social/', 'rusty-17.bsky.social'];
        yield 'a page by did' => [
            'http://bsky.app/profile/did:plc:hczfd45prvqtdrw77256syag',
            'did:plc:hczfd45prvqtdrw77256syag',
        ];
        yield 'a post' => ['https://bsky.app/profile/rusty-17.bsky.social/post/3abc', null];
        yield 'another host' => ['https://example.com/profile/rusty-17.bsky.social', null];
        yield 'no actor syntax' => ['https://bsky.app/profile/some%20name', null];
    }
}
