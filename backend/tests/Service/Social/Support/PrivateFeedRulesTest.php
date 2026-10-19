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
    private const string LIST_REPOSITORY = 'at://did:plc:bob/app.bsky.graph.list/';

    #[DataProvider('verdicts')]
    public function testACollectionMustSuitItsNetwork(
        SocialNetwork $network,
        PrivateFeedModel $privateFeed,
        bool $allowed,
    ): void {
        self::assertSame($allowed, PrivateFeedRules::allows($network, $privateFeed));
    }

    #[DataProvider('mastodonListIds')]
    public function testAMastodonListIdIsDigitsOnly(string $listId, bool $allowed): void
    {
        self::assertSame($allowed, PrivateFeedRules::isMastodonListId($listId));
        self::assertSame(
            $allowed,
            PrivateFeedRules::allows(SocialNetwork::Mastodon, PrivateFeedModel::userList($listId, 'Friends')),
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function mastodonListIds(): iterable
    {
        yield 'digits' => ['42', true];
        yield 'the longest id' => [str_repeat('9', 32), true];
        yield 'an id over 32 digits' => [str_repeat('9', 33), false];
        yield 'empty' => ['', false];
        yield 'a trailing newline' => ["42\n", false];
        yield 'negative' => ['-1', false];
        yield 'a query' => ['42?limit=1', false];
        yield 'a parameter' => ['42&limit=1', false];
        yield 'a parent path' => ['42/../1', false];
        yield 'a trailing letter' => ['42a', false];
    }

    #[DataProvider('mastodonTagNames')]
    public function testAMastodonTagNameIsWordCharactersOnly(string $tagName, bool $allowed): void
    {
        self::assertSame($allowed, PrivateFeedRules::isMastodonTagName($tagName));
        self::assertSame(
            $allowed,
            PrivateFeedRules::allows(SocialNetwork::Mastodon, PrivateFeedModel::followedTag($tagName, '#' . $tagName)),
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function mastodonTagNames(): iterable
    {
        yield 'letters' => ['caturday', true];
        yield 'digits and underscore' => ['100_days', true];
        yield 'non-latin letters' => ['日本語', true];
        yield 'a combining mark' => ["cafe\u{0301}", true];
        yield 'a middle dot' => ["l\u{00B7}l", true];
        yield 'the longest name' => [str_repeat('a', 100), true];
        yield 'a name over 100 characters' => [str_repeat('a', 101), false];
        yield 'empty' => ['', false];
        yield 'a leading hash' => ['#caturday', false];
        yield 'a space' => ['cat day', false];
        yield 'a trailing newline' => ["caturday\n", false];
        yield 'a slash' => ['cat/../day', false];
        yield 'a query' => ['cat?limit=1', false];
        yield 'a colon' => ['cat:day', false];
    }

    /** @return iterable<string, array{SocialNetwork, PrivateFeedModel, bool}> */
    public static function verdicts(): iterable
    {
        yield 'mastodon followed tag' => [
            SocialNetwork::Mastodon,
            PrivateFeedModel::followedTag('caturday', '#caturday'),
            true,
        ];
        yield 'bluesky followed tag' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::followedTag('caturday', '#caturday'),
            false,
        ];
        yield 'trending on mastodon' => [SocialNetwork::Mastodon, PrivateFeedModel::trending(), true];
        yield 'trending on bluesky' => [SocialNetwork::Bluesky, PrivateFeedModel::trending(), false];
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
        yield 'mastodon has no custom feeds' => [
            SocialNetwork::Mastodon,
            PrivateFeedModel::customFeed(self::FEED, 'News'),
            false,
        ];
        yield 'bluesky list with a trailing newline' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::userList(self::LIST . "\n", 'Writers'),
            false,
        ];
        yield 'bluesky list with a query' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::userList(self::LIST . '?limit=100', 'Writers'),
            false,
        ];
        yield 'bluesky list with a parameter' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::userList(self::LIST . '&limit=100', 'Writers'),
            false,
        ];
        yield 'bluesky list with a parent path' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::userList(self::LIST . '/../other', 'Writers'),
            false,
        ];
        yield 'bluesky list with a slash inside the did' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::userList('at://did:plc:bob/evil/app.bsky.graph.list/3l1', 'Writers'),
            false,
        ];
        yield 'bluesky custom feed with a trailing newline' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::customFeed(self::FEED . "\n", 'News'),
            false,
        ];
        yield 'bluesky list with the longest record key' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::userList(self::LIST_REPOSITORY . str_repeat('a', 512), 'Writers'),
            true,
        ];
        yield 'bluesky list with a record key over 512 characters' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::userList(self::LIST_REPOSITORY . str_repeat('a', 513), 'Writers'),
            false,
        ];
        yield 'bluesky author' => [SocialNetwork::Bluesky, PrivateFeedModel::author('did:plc:rusty', 'Rusty'), true];
        yield 'bluesky author named by a handle' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::author('rusty.bsky.social', 'Rusty'),
            false,
        ];
        yield 'bluesky author named by a malformed DID' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::author('did:x&limit=999', 'Rusty'),
            false,
        ];
        yield 'bluesky author named by a DID with a space' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::author('did:a:b c', 'Rusty'),
            false,
        ];
        yield 'mastodon has no author feeds' => [
            SocialNetwork::Mastodon,
            PrivateFeedModel::author('did:plc:rusty', 'Rusty'),
            false,
        ];
        yield 'a list without an id' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::of(SocialFeedKind::UserList, '', 'X'),
            false,
        ];
    }
}
