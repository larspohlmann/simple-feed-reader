<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Support\PrivateFeedSite;
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
        yield 'home' => [PrivateFeedModel::home(), 'https://bsky.app/profile/did:plc:alice'];
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

    public function testAMastodonFeedLinksToItsInstance(): void
    {
        self::assertSame(
            'https://mastodon.example',
            PrivateFeedSite::of(SocialAccounts::mastodon(), PrivateFeedModel::home()),
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
