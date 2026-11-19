<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Enum\SocialNetwork;
use App\Tests\Support\SocialRuleWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileFeedsTest extends TestCase
{
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
        self::assertSame($identity, SocialRuleWiring::profileFeeds()->selectRule($network)->parseIdentity($url));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function communityFeeds(): iterable
    {
        yield 'a community feed' => ['https://feddit.example/feeds/c/rss.xml', 'rss@feddit.example'];
        yield 'upper case and a query' => ['https://Feddit.Example/feeds/c/RSS.xml?sort=New', 'rss@feddit.example'];
        yield 'over http' => ['http://feddit.example/feeds/c/rss.xml', 'rss@feddit.example'];
        yield 'on a port' => ['https://feddit.example:8443/feeds/c/rss.xml', 'rss@feddit.example:8443'];
        yield 'a user feed' => ['https://feddit.example/feeds/u/ada.xml', '@ada@feddit.example'];
        yield 'a user feed with capitals and a query' => [
            'https://Feddit.Example/feeds/u/Ada.xml?sort=New',
            '@ada@feddit.example',
        ];
        yield 'a user feed on a port' => ['https://feddit.example:8443/feeds/u/ada.xml', '@ada@feddit.example:8443'];
        yield 'a nested user path' => ['https://feddit.example/feeds/u/a/b.xml', null];
        yield 'no extension' => ['https://feddit.example/feeds/c/rss', null];
        yield 'a nested path' => ['https://feddit.example/feeds/c/a/b.xml', null];
        yield 'over ftp' => ['ftp://feddit.example/feeds/c/rss.xml', null];
    }

    #[DataProvider('communityFeeds')]
    public function testACommunityFeedIsALemmyProfileFeed(string $url, ?string $identity): void
    {
        $profileFeeds = SocialRuleWiring::profileFeeds();

        self::assertSame($identity, $profileFeeds->selectRule(SocialNetwork::Lemmy)->parseIdentity($url));
        self::assertSame(null === $identity ? null : SocialNetwork::Lemmy, $profileFeeds->findNetwork($url));
    }

    public function testACommunityIdentityBuildsItsCommunityFeed(): void
    {
        $lemmy = SocialRuleWiring::profileFeeds()->selectRule(SocialNetwork::Lemmy);

        self::assertSame('https://feddit.example/feeds/c/rss.xml', $lemmy->buildFeedUrl('rss@feddit.example'));
        self::assertSame(
            'https://feddit.example:8443/feeds/c/rss.xml',
            $lemmy->buildFeedUrl('rss@feddit.example:8443'),
        );
        self::assertNull($lemmy->buildFeedUrl('rss'));
    }

    public function testFindNetworkNamesTheNetworkWhoseProfileFeedTheUrlIs(): void
    {
        $profileFeeds = SocialRuleWiring::profileFeeds();

        self::assertSame(
            SocialNetwork::Bluesky,
            $profileFeeds->findNetwork('https://bsky.app/profile/bob.bsky.social/rss'),
        );
        self::assertSame(SocialNetwork::Mastodon, $profileFeeds->findNetwork('https://mastodon.example/@bob.rss'));
        self::assertNull($profileFeeds->findNetwork('https://example.com/feed.xml'));
    }

    public function testFindRuleIsTheRuleOfTheNetworkWhoseProfileFeedTheUrlIs(): void
    {
        $profileFeeds = SocialRuleWiring::profileFeeds();

        self::assertSame(
            $profileFeeds->selectRule(SocialNetwork::Mastodon),
            $profileFeeds->findRule('https://mastodon.example/@bob.rss'),
        );
        self::assertNull($profileFeeds->findRule('https://example.com/feed.xml'));
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
        self::assertSame($feedUrl, SocialRuleWiring::profileFeeds()->selectRule($network)->buildFeedUrl($identity));
    }

    /** @return iterable<string, array{string, SocialNetwork}> */
    public static function profileFeedUrls(): iterable
    {
        yield 'a Bluesky profile feed' => ['https://bsky.app/profile/did:plc:abc/rss', SocialNetwork::Bluesky];
        yield 'a Mastodon profile feed' => ['https://mastodon.social/@user.rss', SocialNetwork::Mastodon];
        yield 'a Lemmy community feed' => ['https://lemmy.world/feeds/c/tech.xml', SocialNetwork::Lemmy];
        yield 'a Lemmy user feed' => ['https://lemmy.world/feeds/u/sam.xml', SocialNetwork::Lemmy];
    }

    /** The networks were once tried in code order; a rule iterator promises none, so no URL may have two owners. */
    #[DataProvider('profileFeedUrls')]
    public function testExactlyOneRuleClaimsAProfileFeed(string $url, SocialNetwork $owner): void
    {
        $profileFeeds = SocialRuleWiring::profileFeeds();

        foreach (SocialNetwork::cases() as $network) {
            $identity = $profileFeeds->selectRule($network)->parseIdentity($url);
            self::assertSame($network === $owner, null !== $identity, $network->value);
        }
    }
}
