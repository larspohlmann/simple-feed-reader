<?php

declare(strict_types=1);

namespace App\Tests\Service\SavedPosts\Support;

use App\Enum\SocialNetwork;
use App\Service\SavedPosts\Support\SavedFeedUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SavedFeedUrlTest extends TestCase
{
    public function testEachNetworkAndTheFeedsHaveTheirOwnUrlPerUser(): void
    {
        self::assertSame('saved:42', SavedFeedUrl::of(42, null));
        self::assertSame('saved:42:bluesky', SavedFeedUrl::of(42, SocialNetwork::Bluesky));
        self::assertSame('saved:7:mastodon', SavedFeedUrl::of(7, SocialNetwork::Mastodon));
    }

    /** @return iterable<string, array{string, ?SocialNetwork}> */
    public static function networks(): iterable
    {
        yield 'bluesky' => ['saved:42:bluesky', SocialNetwork::Bluesky];
        yield 'mastodon' => ['saved:42:mastodon', SocialNetwork::Mastodon];
        yield 'feeds' => ['saved:42', null];
        yield 'unknown network' => ['saved:42:myspace', null];
        yield 'no user' => ['saved::bluesky', null];
        yield 'a private feed' => ['social:bluesky:42:home', null];
        yield 'a web feed naming a network' => ['https://example.com/saved:42:bluesky', null];
    }

    #[DataProvider('networks')]
    public function testTheNetworkIsReadFromTheUrl(string $url, ?SocialNetwork $network): void
    {
        self::assertSame($network, SavedFeedUrl::networkOf($url));
    }

    public function testOnlyTheSavedSchemeIsSaved(): void
    {
        self::assertTrue(SavedFeedUrl::isSaved('saved:42'));
        self::assertFalse(SavedFeedUrl::isSaved('social:bluesky:42:home'));
        self::assertFalse(SavedFeedUrl::isSaved('https://saved.example/feed'));
    }

    public function testReboundNamesTheSameFeedOfAnotherUserAndLeavesOtherUrlsAlone(): void
    {
        self::assertSame('saved:9:bluesky', SavedFeedUrl::rebound('saved:42:bluesky', 9));
        self::assertSame('saved:9', SavedFeedUrl::rebound('saved:42', 9));
        self::assertSame('https://example.com/feed.xml', SavedFeedUrl::rebound('https://example.com/feed.xml', 9));
    }
}
