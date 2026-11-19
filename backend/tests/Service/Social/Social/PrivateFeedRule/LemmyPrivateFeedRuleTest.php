<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\PrivateFeedRule;

use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Service\Social\Social\Model\StoredPostModel;
use App\Service\Social\Social\PrivateFeedRule\LemmyPrivateFeedRule;
use App\Service\Social\Social\Support\SocialSearchMatch;
use App\Service\Social\Social\Support\SocialSearchQuery;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LemmyPrivateFeedRuleTest extends TestCase
{
    private const string FEED = 'at://did:plc:bob/app.bsky.feed.generator/news';

    public function testASearchWithoutATermIsNotAllowed(): void
    {
        self::assertFalse(new LemmyPrivateFeedRule()->allows(PrivateFeedModel::search('-gazette')));
    }

    public function testASearchWithAlternativesIsAllowed(): void
    {
        self::assertTrue(new LemmyPrivateFeedRule()->allows(PrivateFeedModel::search('rss || atom')));
    }

    public function testAPlainSearchIsTaken(): void
    {
        $rule = new LemmyPrivateFeedRule();

        self::assertFalse($rule->allows(PrivateFeedModel::search('')));
        self::assertFalse($rule->allows(PrivateFeedModel::search(str_repeat('a', SocialSearchQuery::MAX_LENGTH + 1))));
        self::assertTrue($rule->allows(PrivateFeedModel::search(str_repeat('ä', SocialSearchQuery::MAX_LENGTH))));
    }

    #[DataProvider('verdicts')]
    public function testItsTimelinesAndNoOtherCollectionAreAllowed(PrivateFeedModel $privateFeed, bool $allowed): void
    {
        self::assertSame($allowed, new LemmyPrivateFeedRule()->allows($privateFeed));
    }

    /** @return iterable<string, array{PrivateFeedModel, bool}> */
    public static function verdicts(): iterable
    {
        yield 'subscribed' => [PrivateFeedModel::timeline(SocialFeedKind::Home), true];
        yield 'local' => [PrivateFeedModel::timeline(SocialFeedKind::Local), true];
        yield 'all' => [PrivateFeedModel::timeline(SocialFeedKind::All), true];
        yield 'list' => [PrivateFeedModel::userList('1', 'Friends'), false];
        yield 'custom feed' => [PrivateFeedModel::customFeed(self::FEED, 'News'), false];
        yield 'author' => [PrivateFeedModel::author('did:plc:rusty', 'Rusty'), false];
        yield 'tag' => [PrivateFeedModel::followedTag('caturday', '#caturday'), false];
        yield 'trending' => [PrivateFeedModel::timeline(SocialFeedKind::Trending), false];
    }

    #[DataProvider('timelines')]
    public function testAFeedIsItsListingPageOrTheInstance(PrivateFeedModel $timeline, string $site): void
    {
        self::assertSame(
            $site,
            new LemmyPrivateFeedRule()->buildSiteUrl(SocialAccounts::lemmyOf(SocialAccounts::owner()), $timeline),
        );
    }

    /** @return iterable<string, array{PrivateFeedModel, string}> */
    public static function timelines(): iterable
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

    public function testASearchIsItsSearchPage(): void
    {
        self::assertSame(
            'https://lemmy.example/search?q=rss%20reader&type=Posts',
            new LemmyPrivateFeedRule()->buildSiteUrl(
                SocialAccounts::lemmyOf(SocialAccounts::owner()),
                PrivateFeedModel::search('rss reader'),
            ),
        );
    }

    public function testOneTermIsAskedPerAlternative(): void
    {
        self::assertSame(
            ['rss reader', 'atom'],
            new LemmyPrivateFeedRule()->buildSearchQueries('"rss reader" || atom'),
        );
    }

    public function testAQueryWithoutATermDoesNotFit(): void
    {
        $this->expectException(SearchQueryTooBroadException::class);

        new LemmyPrivateFeedRule()->buildSearchQueries('-gazette');
    }

    public function testNineAlternativesDoNotFit(): void
    {
        $this->expectException(SearchQueryTooBroadException::class);

        new LemmyPrivateFeedRule()->buildSearchQueries('a || b || c || d || e || f || g || h || i');
    }

    public function testAPostIsJudgedByTitleAndBody(): void
    {
        $post = new StoredPostModel(
            1,
            'https://lemmy.example/post/1',
            'rss reader',
            '<p>atom</p>',
            'https://lemmy.example/u/ada',
        );

        $account = SocialAccounts::lemmyOf(SocialAccounts::owner());

        $searched = new LemmyPrivateFeedRule()->buildSearchedPost($account, $post);

        self::assertTrue(SocialSearchMatch::matcher('reader atom')($searched));
    }

    public function testSourceFormat(): void
    {
        self::assertSame(SourceFormat::LEMMY_API, new LemmyPrivateFeedRule()->sourceFormat());
    }

    public function testHasFollowersOnlyPosts(): void
    {
        self::assertFalse(new LemmyPrivateFeedRule()->hasFollowersOnlyPosts());
    }
}
