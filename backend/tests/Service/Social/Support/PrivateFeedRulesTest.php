<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Support\PrivateFeedRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrivateFeedRulesTest extends TestCase
{
    private const string LIST = 'at://did:plc:bob/app.bsky.graph.list/3l1';
    private const string FEED = 'at://did:plc:bob/app.bsky.feed.generator/news';

    #[DataProvider('verdicts')]
    public function testACollectionMustSuitItsNetwork(
        SocialNetwork $network,
        PrivateFeedModel $privateFeed,
        bool $allowed,
    ): void {
        self::assertSame($allowed, PrivateFeedRules::allows($network, $privateFeed));
    }

    /** @return iterable<string, array{SocialNetwork, PrivateFeedModel, bool}> */
    public static function verdicts(): iterable
    {
        yield 'home on bluesky' => [SocialNetwork::Bluesky, PrivateFeedModel::home(), true];
        yield 'home on mastodon' => [SocialNetwork::Mastodon, PrivateFeedModel::home(), true];
        yield 'bluesky list' => [SocialNetwork::Bluesky, PrivateFeedModel::userList(self::LIST, 'Writers'), true];
        yield 'bluesky list named by a feed uri' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::userList(self::FEED, 'Writers'),
            false,
        ];
        yield 'bluesky custom feed' => [SocialNetwork::Bluesky, PrivateFeedModel::customFeed(self::FEED, 'News'), true];
        yield 'bluesky custom feed named by a list uri' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::customFeed(self::LIST, 'News'),
            false,
        ];
        yield 'mastodon list' => [SocialNetwork::Mastodon, PrivateFeedModel::userList('42', 'Friends'), true];
        yield 'mastodon list named by a uri' => [
            SocialNetwork::Mastodon,
            PrivateFeedModel::userList(self::LIST, 'Friends'),
            false,
        ];
        yield 'mastodon list id with a trailing letter' => [
            SocialNetwork::Mastodon,
            PrivateFeedModel::userList('42a', 'Friends'),
            false,
        ];
        yield 'mastodon has no custom feeds' => [
            SocialNetwork::Mastodon,
            PrivateFeedModel::customFeed(self::FEED, 'News'),
            false,
        ];
        yield 'a list without an id' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::of(SocialFeedKind::UserList, null, 'X'),
            false,
        ];
    }
}
