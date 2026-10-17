<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Support\ProfileIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileIdentityTest extends TestCase
{
    #[DataProvider('feeds')]
    public function testAFeedFollowsThePersonItsAddressNames(
        SocialNetwork $network,
        string $feedUrl,
        ?string $identity,
    ): void {
        self::assertSame($identity, ProfileIdentity::of($network, $feedUrl));
    }

    /** @return iterable<string, array{SocialNetwork, string, ?string}> */
    public static function feeds(): iterable
    {
        yield 'a profile rss feed' => [
            SocialNetwork::Bluesky,
            'https://bsky.app/profile/did:plc:bob/rss',
            'did:plc:bob',
        ];
        yield 'an author feed' => [SocialNetwork::Bluesky, 'social:bluesky:3:author:did:plc:rusty', 'did:plc:rusty'];
        yield 'a home timeline' => [SocialNetwork::Bluesky, 'social:bluesky:3:home', null];
        yield 'an ordinary feed' => [SocialNetwork::Bluesky, 'https://example.com/feed.xml', null];
    }

    #[DataProvider('blueskyFeeds')]
    public function testABlueskyPersonFeedNamesAValidActor(
        string $feedUrl,
        ?string $ofAnyPersonFeed,
        ?string $ofAnRssFeed,
    ): void {
        self::assertSame($ofAnyPersonFeed, ProfileIdentity::validBlueskyActorOf($feedUrl));
        self::assertSame($ofAnRssFeed, ProfileIdentity::validBlueskyActorOfRssFeed($feedUrl));
    }

    /** @return iterable<string, array{string, ?string, ?string}> */
    public static function blueskyFeeds(): iterable
    {
        yield 'a profile rss feed' => ['https://bsky.app/profile/did:plc:bob/rss', 'did:plc:bob', 'did:plc:bob'];
        yield 'an author feed' => ['social:bluesky:3:author:did:plc:rusty', 'did:plc:rusty', null];
        yield 'an rss feed naming no actor' => ['https://bsky.app/profile/not_an_actor!/rss', null, null];
        yield 'a mastodon profile feed' => ['https://mastodon.example/@alice.rss', null, null];
    }
}
