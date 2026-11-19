<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\PrivateFeedRule;

use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Service\Social\Social\PrivateFeedRule\MastodonPrivateFeedRule;
use App\Service\Social\Social\Support\MastodonFeedIds;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonPrivateFeedRuleTest extends TestCase
{
    private const string LIST = 'at://did:plc:bob/app.bsky.graph.list/3l1';
    private const string FEED = 'at://did:plc:bob/app.bsky.feed.generator/news';
    private const string TOO_BROAD = '(a || b || c) (d || e || f)';

    #[DataProvider('verdicts')]
    public function testACollectionMustSuitMastodon(PrivateFeedModel $privateFeed, bool $allowed): void
    {
        self::assertSame($allowed, new MastodonPrivateFeedRule()->allows($privateFeed));
    }

    /** @return iterable<string, array{PrivateFeedModel, bool}> */
    public static function verdicts(): iterable
    {
        yield 'followed tag' => [PrivateFeedModel::followedTag('caturday', '#caturday'), true];
        yield 'trending' => [PrivateFeedModel::timeline(SocialFeedKind::Trending), true];
        yield 'home' => [PrivateFeedModel::timeline(SocialFeedKind::Home), true];
        yield 'list' => [PrivateFeedModel::userList('42', 'Friends'), true];
        yield 'list named by a uri' => [PrivateFeedModel::userList(self::LIST, 'Friends'), false];
        yield 'no custom feeds' => [PrivateFeedModel::customFeed(self::FEED, 'News'), false];
        yield 'no author feeds' => [PrivateFeedModel::author('did:plc:rusty', 'Rusty'), false];
        yield 'local' => [PrivateFeedModel::timeline(SocialFeedKind::Local), false];
        yield 'all' => [PrivateFeedModel::timeline(SocialFeedKind::All), false];
    }

    #[DataProvider('searchesWithinTheCap')]
    public function testASearchWithinTheCapIsAllowed(string $query): void
    {
        self::assertTrue(new MastodonPrivateFeedRule()->allows(PrivateFeedModel::search($query)));
    }

    /** @return iterable<string, array{string}> */
    public static function searchesWithinTheCap(): iterable
    {
        yield 'a phrase' => ['rss reader'];
        yield 'a word' => ['rss'];
    }

    public function testASearchIsAllowedOnlyWhileItSpellsOutFewEnoughAlternatives(): void
    {
        $rule = new MastodonPrivateFeedRule();

        self::assertFalse($rule->allows(PrivateFeedModel::search(self::TOO_BROAD)));
        self::assertTrue($rule->allows(PrivateFeedModel::search('(a || b) (c || d)')));
    }

    public function testAQueryWithinTheCapBuildsItsSearch(): void
    {
        self::assertSame(['rss'], new MastodonPrivateFeedRule()->buildSearchQueries('rss'));
    }

    public function testAQueryBeyondTheCapThrows(): void
    {
        $this->expectException(SearchQueryTooBroadException::class);

        new MastodonPrivateFeedRule()->buildSearchQueries(self::TOO_BROAD);
    }

    public function testOneSearchRunsPerAlternative(): void
    {
        self::assertSame(['a', 'b'], new MastodonPrivateFeedRule()->buildSearchQueries('a || b'));
    }

    #[DataProvider('listIds')]
    public function testAListIdIsDigitsOnly(string $listId, bool $allowed): void
    {
        self::assertSame($allowed, MastodonFeedIds::isListId($listId));
        self::assertSame(
            $allowed,
            new MastodonPrivateFeedRule()->allows(PrivateFeedModel::userList($listId, 'Friends')),
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function listIds(): iterable
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

    #[DataProvider('tagNames')]
    public function testATagNameIsWordCharactersOnly(string $tagName, bool $allowed): void
    {
        self::assertSame($allowed, MastodonFeedIds::isTagName($tagName));
        self::assertSame(
            $allowed,
            new MastodonPrivateFeedRule()->allows(PrivateFeedModel::followedTag($tagName, '#' . $tagName)),
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function tagNames(): iterable
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

    public function testAFeedLinksToItsInstance(): void
    {
        $rule = new MastodonPrivateFeedRule();
        $account = SocialAccounts::mastodon();

        self::assertSame(
            'https://mastodon.example',
            $rule->buildSiteUrl($account, PrivateFeedModel::timeline(SocialFeedKind::Home)),
        );
        self::assertSame(
            'https://mastodon.example/explore',
            $rule->buildSiteUrl($account, PrivateFeedModel::timeline(SocialFeedKind::Trending)),
        );
        self::assertSame(
            'https://mastodon.example/lists/42',
            $rule->buildSiteUrl($account, PrivateFeedModel::userList('42', 'Friends')),
        );
        self::assertSame(
            'https://mastodon.example/tags/caturday',
            $rule->buildSiteUrl($account, PrivateFeedModel::followedTag('Caturday', '#Caturday')),
        );
        self::assertSame(
            'https://mastodon.example/tags/' . rawurlencode('日本語'),
            $rule->buildSiteUrl($account, PrivateFeedModel::followedTag('日本語', '#日本語')),
        );
    }

    public function testASearchOpensTheSameSearchOnTheInstance(): void
    {
        self::assertSame(
            'https://mastodon.example.test/search?q=%22rss%20reader%22%20%7C%7C%20feedreader',
            new MastodonPrivateFeedRule()->buildSiteUrl(
                SocialAccounts::mastodonOn('https://mastodon.example.test/'),
                PrivateFeedModel::search('"rss reader" || feedreader'),
            ),
        );
    }

    public function testAnAuthorOrCustomFeedLinksToTheInstance(): void
    {
        $rule = new MastodonPrivateFeedRule();

        self::assertSame(
            'https://mastodon.example',
            $rule->buildSiteUrl(SocialAccounts::mastodon(), PrivateFeedModel::author('109', 'Alice')),
        );
        self::assertSame(
            'https://mastodon.example',
            $rule->buildSiteUrl(SocialAccounts::mastodon(), PrivateFeedModel::customFeed('feed', 'Art')),
        );
    }

    public function testAnInstanceUrlWithATrailingSlashLinksWithoutADoubleSlash(): void
    {
        self::assertSame(
            'https://mastodon.example/lists/42',
            new MastodonPrivateFeedRule()->buildSiteUrl(
                SocialAccounts::mastodonOn('https://mastodon.example/'),
                PrivateFeedModel::userList('42', 'Friends'),
            ),
        );
    }

    public function testSourceFormat(): void
    {
        self::assertSame(SourceFormat::MASTODON_API, new MastodonPrivateFeedRule()->sourceFormat());
    }
}
