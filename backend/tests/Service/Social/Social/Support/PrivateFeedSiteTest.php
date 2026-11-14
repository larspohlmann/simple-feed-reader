<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Enum\SocialFeedKind;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Service\Social\Social\Support\PrivateFeedSite;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrivateFeedSiteTest extends TestCase
{
    #[DataProvider('blueskyFeeds')]
    public function testABlueskyFeedLinksToItsPageOnBsky(PrivateFeedModel $privateFeed, string $site): void
    {
        self::assertSame($site, PrivateFeedSite::of(SocialAccounts::bluesky(), $privateFeed));
    }

    /** @return iterable<string, array{PrivateFeedModel, string}> */
    public static function blueskyFeeds(): iterable
    {
        yield 'home' => [PrivateFeedModel::timeline(SocialFeedKind::Home), 'https://bsky.app/profile/did:plc:alice'];
        yield 'list' => [
            PrivateFeedModel::userList('at://did:plc:bob/app.bsky.graph.list/3l1', 'Writers'),
            'https://bsky.app/profile/did:plc:bob/lists/3l1',
        ];
        yield 'custom feed' => [
            PrivateFeedModel::customFeed('at://did:plc:bob/app.bsky.feed.generator/news', 'News'),
            'https://bsky.app/profile/did:plc:bob/feed/news',
        ];
        yield 'author' => [
            PrivateFeedModel::author('did:plc:rusty', 'Rusty'),
            'https://bsky.app/profile/did:plc:rusty',
        ];
        yield 'an unreadable uri' => [
            PrivateFeedModel::userList('not-a-uri', 'Odd'),
            'https://bsky.app/profile/did:plc:alice',
        ];
    }

    #[DataProvider('lemmyTimelines')]
    public function testALemmyFeedIsItsListingPageOrTheInstance(PrivateFeedModel $timeline, string $site): void
    {
        self::assertSame($site, PrivateFeedSite::of(SocialAccounts::lemmyOf(SocialAccounts::owner()), $timeline));
    }

    /** @return iterable<string, array{PrivateFeedModel, string}> */
    public static function lemmyTimelines(): iterable
    {
        $listing = 'https://lemmy.example/?listingType=';
        yield 'subscribed' => [PrivateFeedModel::timeline(SocialFeedKind::Home), $listing . 'Subscribed'];
        yield 'local' => [PrivateFeedModel::timeline(SocialFeedKind::Local), $listing . 'Local'];
        yield 'all' => [PrivateFeedModel::timeline(SocialFeedKind::All), $listing . 'All'];
        yield 'list' => [PrivateFeedModel::of(SocialFeedKind::UserList, '1', 'Friends'), 'https://lemmy.example'];
        yield 'custom feed' => [PrivateFeedModel::of(SocialFeedKind::CustomFeed, 'f', 'F'), 'https://lemmy.example'];
        yield 'author' => [PrivateFeedModel::of(SocialFeedKind::Author, 'did:plc:a', 'A'), 'https://lemmy.example'];
        yield 'tag' => [PrivateFeedModel::of(SocialFeedKind::Tag, 'caturday', '#caturday'), 'https://lemmy.example'];
        yield 'trending' => [PrivateFeedModel::timeline(SocialFeedKind::Trending), 'https://lemmy.example'];
    }

    public function testALemmySearchIsItsSearchPage(): void
    {
        self::assertSame(
            'https://lemmy.example/search?q=rss%20reader&type=Posts',
            PrivateFeedSite::of(
                SocialAccounts::lemmyOf(SocialAccounts::owner()),
                PrivateFeedModel::search('rss reader'),
            ),
        );
    }

    public function testAMastodonFeedLinksToItsInstance(): void
    {
        self::assertSame(
            'https://mastodon.example',
            PrivateFeedSite::of(SocialAccounts::mastodon(), PrivateFeedModel::timeline(SocialFeedKind::Home)),
        );
        self::assertSame(
            'https://mastodon.example/explore',
            PrivateFeedSite::of(SocialAccounts::mastodon(), PrivateFeedModel::timeline(SocialFeedKind::Trending)),
        );
        self::assertSame(
            'https://mastodon.example/lists/42',
            PrivateFeedSite::of(SocialAccounts::mastodon(), PrivateFeedModel::userList('42', 'Friends')),
        );
        self::assertSame(
            'https://mastodon.example/tags/caturday',
            PrivateFeedSite::of(SocialAccounts::mastodon(), PrivateFeedModel::followedTag('Caturday', '#Caturday')),
        );
        self::assertSame(
            'https://mastodon.example/tags/' . rawurlencode('日本語'),
            PrivateFeedSite::of(SocialAccounts::mastodon(), PrivateFeedModel::followedTag('日本語', '#日本語')),
        );
    }

    public function testASearchOpensTheSameSearchOnTheNetwork(): void
    {
        $search = PrivateFeedModel::search('"rss reader" || feedreader');

        self::assertSame(
            'https://bsky.app/search?q=%22rss%20reader%22%20%7C%7C%20feedreader',
            PrivateFeedSite::of(SocialAccounts::bluesky(), $search),
        );
        self::assertSame(
            'https://mastodon.example.test/search?q=%22rss%20reader%22%20%7C%7C%20feedreader',
            PrivateFeedSite::of(SocialAccounts::mastodonOn('https://mastodon.example.test/'), $search),
        );
    }

    public function testAMastodonAuthorOrCustomFeedLinksToTheInstance(): void
    {
        self::assertSame(
            'https://mastodon.example',
            PrivateFeedSite::of(SocialAccounts::mastodon(), PrivateFeedModel::author('109', 'Alice')),
        );
        self::assertSame(
            'https://mastodon.example',
            PrivateFeedSite::of(SocialAccounts::mastodon(), PrivateFeedModel::customFeed('feed', 'Art')),
        );
    }

    public function testAnInstanceUrlWithATrailingSlashLinksWithoutADoubleSlash(): void
    {
        self::assertSame(
            'https://mastodon.example/lists/42',
            PrivateFeedSite::of(
                SocialAccounts::mastodonOn('https://mastodon.example/'),
                PrivateFeedModel::userList('42', 'Friends'),
            ),
        );
    }
}
