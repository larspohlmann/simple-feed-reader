<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Social\Support\ProfileIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileIdentityTest extends TestCase
{
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
