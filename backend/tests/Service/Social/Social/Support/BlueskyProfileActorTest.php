<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Social\Support\BlueskyProfileActor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyProfileActorTest extends TestCase
{
    #[DataProvider('blueskyFeeds')]
    public function testABlueskyPersonFeedNamesAValidActor(
        string $feedUrl,
        ?string $ofAnyPersonFeed,
        ?string $ofAnRssFeed,
    ): void {
        self::assertSame($ofAnyPersonFeed, BlueskyProfileActor::parseFollowedActor($feedUrl));
        self::assertSame($ofAnRssFeed, BlueskyProfileActor::parseRssActor($feedUrl));
    }

    /** @return iterable<string, array{string, ?string, ?string}> */
    public static function blueskyFeeds(): iterable
    {
        yield 'a profile rss feed' => ['https://bsky.app/profile/did:plc:bob/rss', 'did:plc:bob', 'did:plc:bob'];
        yield 'an author feed' => ['social:bluesky:3:author:did:plc:rusty', 'did:plc:rusty', null];
        yield 'an rss feed naming no actor' => ['https://bsky.app/profile/not_an_actor!/rss', null, null];
        yield 'a mastodon profile feed' => ['https://mastodon.example/@alice.rss', null, null];
    }

    #[DataProvider('authorDidFeeds')]
    public function testParseAuthorDidReadsOnlyADidAuthorFeed(string $feedUrl, ?string $expected): void
    {
        self::assertSame($expected, BlueskyProfileActor::parseAuthorDid($feedUrl));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function authorDidFeeds(): iterable
    {
        yield 'a did author feed' => ['social:bluesky:3:author:did:plc:rusty', 'did:plc:rusty'];
        yield 'a handle author feed' => ['social:bluesky:3:author:rusty.bsky.social', null];
        yield 'not an author feed' => ['https://bsky.app/profile/did:plc:bob/rss', null];
    }

    public function testParseFollowedActorRefusesANonDidAuthorFeed(): void
    {
        self::assertNull(BlueskyProfileActor::parseFollowedActor('social:bluesky:12:author:rusty.bsky.social'));
        self::assertNull(BlueskyProfileActor::parseFollowedActor('social:bluesky:12:author:did:x&limit=999'));
        self::assertNull(BlueskyProfileActor::parseFollowedActor('social:bluesky:12:author:did:a:b c'));
    }
}
