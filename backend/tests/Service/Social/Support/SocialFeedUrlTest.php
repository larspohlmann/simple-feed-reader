<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\SocialFeedAddressModel;
use App\Service\Social\Support\SocialFeedUrl;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SocialFeedUrlTest extends TestCase
{
    use AssignsEntityIds;

    private const string LIST = 'at://did:plc:bob/app.bsky.graph.list/3l1';
    private const string FEED = 'at://did:plc:bob/app.bsky.feed.generator/news';

    #[DataProvider('blueskyFeeds')]
    public function testAPrivateFeedIsAddressedByItsAccountNotItsDid(
        PrivateFeedModel $privateFeed,
        string $url,
    ): void {
        self::assertSame($url, SocialFeedUrl::of(self::withId(SocialAccounts::bluesky(), 12), $privateFeed));
    }

    /** @return iterable<string, array{PrivateFeedModel, string}> */
    public static function blueskyFeeds(): iterable
    {
        yield 'home' => [PrivateFeedModel::home(), 'social:bluesky:12:home'];
        yield 'list' => [PrivateFeedModel::userList(self::LIST, 'Writers'), 'social:bluesky:12:list:' . self::LIST];
        yield 'feed' => [PrivateFeedModel::customFeed(self::FEED, 'News'), 'social:bluesky:12:feed:' . self::FEED];
    }

    public function testAMastodonListIsAddressedByItsId(): void
    {
        self::assertSame(
            'social:mastodon:4:list:42',
            SocialFeedUrl::of(self::withId(SocialAccounts::mastodon(), 4), PrivateFeedModel::userList('42', 'Friends')),
        );
    }

    #[DataProvider('addresses')]
    public function testAnAddressIsReadBackFromTheUrl(string $url, ?SocialFeedAddressModel $address): void
    {
        self::assertEquals($address, SocialFeedUrl::addressOf($url));
    }

    /** @return iterable<string, array{string, ?SocialFeedAddressModel}> */
    public static function addresses(): iterable
    {
        yield 'home' => [
            'social:bluesky:12:home',
            new SocialFeedAddressModel(SocialNetwork::Bluesky, 12, SocialFeedKind::Home),
        ];
        yield 'list with colons' => [
            'social:bluesky:12:list:' . self::LIST,
            new SocialFeedAddressModel(SocialNetwork::Bluesky, 12, SocialFeedKind::UserList),
        ];
        yield 'custom feed' => [
            'social:bluesky:12:feed:' . self::FEED,
            new SocialFeedAddressModel(SocialNetwork::Bluesky, 12, SocialFeedKind::CustomFeed),
        ];
        yield 'mastodon list' => [
            'social:mastodon:4:list:42',
            new SocialFeedAddressModel(SocialNetwork::Mastodon, 4, SocialFeedKind::UserList),
        ];
        yield 'an rss feed' => ['https://bsky.app/profile/did:plc:bob/rss', null];
        yield 'an unknown network' => ['social:threads:4:home', null];
        yield 'an unknown kind' => ['social:bluesky:4:starter-pack:x', null];
        yield 'no account id' => ['social:bluesky:alice:home', null];
    }

    public function testOnlyTheSocialSchemeIsPrivate(): void
    {
        self::assertTrue(SocialFeedUrl::isPrivate('social:bluesky:12:home'));
        self::assertFalse(SocialFeedUrl::isPrivate('https://example.com/social:bluesky'));
    }
}
