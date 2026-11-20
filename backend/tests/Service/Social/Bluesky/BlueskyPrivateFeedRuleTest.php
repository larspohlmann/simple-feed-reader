<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Bluesky;

use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Service\Social\Bluesky\BlueskyPrivateFeedRule;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyPrivateFeedRuleTest extends TestCase
{
    private const string LIST = 'at://did:plc:bob/app.bsky.graph.list/3l1';
    private const string FEED = 'at://did:plc:bob/app.bsky.feed.generator/news';
    private const string LIST_REPOSITORY = 'at://did:plc:bob/app.bsky.graph.list/';

    #[DataProvider('verdicts')]
    public function testACollectionMustSuitBluesky(PrivateFeedModel $privateFeed, bool $allowed): void
    {
        self::assertSame($allowed, new BlueskyPrivateFeedRule()->allows($privateFeed));
    }

    /** @return iterable<string, array{PrivateFeedModel, bool}> */
    public static function verdicts(): iterable
    {
        yield 'followed tag' => [PrivateFeedModel::followedTag('caturday', '#caturday'), false];
        yield 'trending' => [PrivateFeedModel::timeline(SocialFeedKind::Trending), false];
        yield 'home' => [PrivateFeedModel::timeline(SocialFeedKind::Home), true];
        yield 'list' => [PrivateFeedModel::userList(self::LIST, 'Writers'), true];
        yield 'list named by a feed uri' => [PrivateFeedModel::userList(self::FEED, 'Writers'), false];
        yield 'custom feed' => [PrivateFeedModel::customFeed(self::FEED, 'News'), true];
        yield 'custom feed named by a list uri' => [PrivateFeedModel::customFeed(self::LIST, 'News'), false];
        yield 'list with a trailing newline' => [PrivateFeedModel::userList(self::LIST . "\n", 'Writers'), false];
        yield 'list with a query' => [PrivateFeedModel::userList(self::LIST . '?limit=100', 'Writers'), false];
        yield 'list with a parameter' => [PrivateFeedModel::userList(self::LIST . '&limit=100', 'Writers'), false];
        yield 'list with a parent path' => [PrivateFeedModel::userList(self::LIST . '/../other', 'Writers'), false];
        yield 'list with a slash inside the did' => [
            PrivateFeedModel::userList('at://did:plc:bob/evil/app.bsky.graph.list/3l1', 'Writers'),
            false,
        ];
        yield 'custom feed with a trailing newline' => [
            PrivateFeedModel::customFeed(self::FEED . "\n", 'News'),
            false,
        ];
        yield 'list with the longest record key' => [
            PrivateFeedModel::userList(self::LIST_REPOSITORY . str_repeat('a', 512), 'Writers'),
            true,
        ];
        yield 'list with a record key over 512 characters' => [
            PrivateFeedModel::userList(self::LIST_REPOSITORY . str_repeat('a', 513), 'Writers'),
            false,
        ];
        yield 'author' => [PrivateFeedModel::author('did:plc:rusty', 'Rusty'), true];
        yield 'author named by a handle' => [PrivateFeedModel::author('rusty.bsky.social', 'Rusty'), false];
        yield 'author named by a malformed DID' => [PrivateFeedModel::author('did:x&limit=999', 'Rusty'), false];
        yield 'author named by a DID with a space' => [PrivateFeedModel::author('did:a:b c', 'Rusty'), false];
        yield 'a list without an id' => [PrivateFeedModel::fromKind(SocialFeedKind::UserList, '', 'X'), false];
        yield 'local' => [PrivateFeedModel::timeline(SocialFeedKind::Local), false];
        yield 'all' => [PrivateFeedModel::timeline(SocialFeedKind::All), false];
    }

    public function testASearchIsAllowedWhenItHasAQuery(): void
    {
        $rule = new BlueskyPrivateFeedRule();

        self::assertTrue($rule->allows(PrivateFeedModel::search('rss reader')));
        self::assertFalse($rule->allows(PrivateFeedModel::search("  \t ")));
        self::assertFalse($rule->allows(PrivateFeedModel::search(str_repeat('a', 513))));
        self::assertTrue($rule->allows(PrivateFeedModel::search(str_repeat('ä', 512))));
    }

    public function testASearchOfManyAlternativesIsAllowed(): void
    {
        self::assertTrue(new BlueskyPrivateFeedRule()->allows(PrivateFeedModel::search('(a || b || c) (d || e || f)')));
    }

    #[DataProvider('feeds')]
    public function testAFeedLinksToItsPageOnBsky(PrivateFeedModel $privateFeed, string $site): void
    {
        self::assertSame($site, new BlueskyPrivateFeedRule()->buildSiteUrl(SocialAccounts::bluesky(), $privateFeed));
    }

    /** @return iterable<string, array{PrivateFeedModel, string}> */
    public static function feeds(): iterable
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

    public function testASearchOpensTheSameSearchOnBsky(): void
    {
        self::assertSame(
            'https://bsky.app/search?q=%22rss%20reader%22%20%7C%7C%20feedreader',
            new BlueskyPrivateFeedRule()->buildSiteUrl(
                SocialAccounts::bluesky(),
                PrivateFeedModel::search('"rss reader" || feedreader'),
            ),
        );
    }

    public function testTheQueryRunsAsWritten(): void
    {
        self::assertSame(['a || b'], new BlueskyPrivateFeedRule()->buildSearchQueries('a || b'));
    }

    public function testSourceFormat(): void
    {
        self::assertSame(SourceFormat::BLUESKY_API, new BlueskyPrivateFeedRule()->sourceFormat());
    }

    public function testHasFollowersOnlyPosts(): void
    {
        self::assertFalse(new BlueskyPrivateFeedRule()->hasFollowersOnlyPosts());
    }
}
