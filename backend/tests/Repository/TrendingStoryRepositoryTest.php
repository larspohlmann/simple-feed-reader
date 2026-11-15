<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\Feed;
use App\Entity\TrendingStory;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\TrendingWindow;
use App\Repository\TrendingEntryRepository;
use App\Repository\TrendingStoryRepository;
use App\Service\Curation\Trending\Model\RankedStoryModel;
use App\Service\Curation\Trending\ReaderWindows;
use App\Service\Curation\Trending\TrendingStories;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;
use Symfony\Component\Clock\MockClock;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Tests\Support\SharedLinks;

final class TrendingStoryRepositoryTest extends DbTestCase
{
    private const string POST = 'https://social.example/@poster/1';
    private const string OTHER_POST = 'https://social.example/@other/1';
    private const string SAME_POSTERS_OTHER_POST = 'https://social.example/@poster/2';

    private TrendingFixtures $fixtures;
    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new TrendingFixtures($this->entityManager);
        $this->reader = new User('sites-reader@example.com', new \DateTimeImmutable('2031-01-01 00:00:00'));
        $this->entityManager->persist($this->reader);
    }

    public function testOneArticleFeedAndAPostSharingAnotherSiteAreTwoSources(): void
    {
        $story = $this->storyOf([
            [$this->articleFeed('outlet'), 'outlet.example'],
            [$this->socialFeed('ann'), 'other.example'],
        ]);

        self::assertSame([$story->requireId()], $this->shownStoryIds());
    }

    public function testOneArticleFeedAndAPostSharingItsSiteAreOneSource(): void
    {
        $this->storyOf([
            [$this->articleFeed('outlet'), 'outlet.example'],
            [$this->socialFeed('ann'), 'outlet.example'],
        ]);

        self::assertSame([], $this->shownStoryIds());
    }

    public function testTwoArticleFeedsOfOneSiteAreStillTwoSources(): void
    {
        $story = $this->storyOf([
            [$this->articleFeed('outlet'), 'outlet.example'],
            [$this->articleFeed('outlet-digest'), 'outlet.example'],
        ]);

        self::assertSame([$story->requireId()], $this->shownStoryIds());
    }

    public function testPostsSharingTwoSitesCarryAStoryWithoutAnArticleFeed(): void
    {
        $story = $this->storyOf([
            [$this->socialFeed('ann'), 'outlet.example'],
            [$this->socialFeed('ben'), 'other.example'],
        ]);

        self::assertSame([$story->requireId()], $this->shownStoryIds());
    }

    public function testPostsWhoseCardsNameNoStoryAddNoSource(): void
    {
        $this->storyOf([
            [$this->articleFeed('outlet'), 'outlet.example'],
            [$this->socialFeed('ann'), null],
            [$this->socialFeed('ben'), null],
        ]);

        self::assertSame([], $this->shownStoryIds());
    }

    public function testTheShownEntriesAreArticlesAndPostsSharingAStoryCard(): void
    {
        $article = $this->member($this->articleFeed('outlet'));
        $carded = $this->member($this->socialFeed('ann'));
        $uncarded = $this->member($this->socialFeed('ben'));
        $story = $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', [
            [$article, 'outlet.example'],
            [$carded, 'other.example'],
            [$uncarded, null],
        ]);

        self::assertSame(
            [$story->requireId() => [$article->requireId(), $carded->requireId()]],
            $this->stories()->shownEntriesByStory($this->reader->requireId(), [$story->requireId()])->entryIdsByStory(),
        );
        self::assertSame([], $this->stories()->shownEntriesByStory($this->reader->requireId(), [])->entryIdsByStory());
    }

    public function testShownEntriesCarryTheirLinksHash(): void
    {
        $article = $this->member($this->articleFeed('outlet'));
        $carded = $this->member($this->socialFeed('ann'));
        $this->entityManager->persist(new LinkCard($carded, 0, SharedLinks::of(
            $this->entityManager,
            new LinkCardContent('https://outlet.example/storm', 'Storm', null, null, 'Outlet'),
        )));
        $story = $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', [
            [$article, 'outlet.example'],
            [$carded, 'outlet.example'],
        ]);

        $shown = $this->stories()->shownEntriesByStory($this->reader->requireId(), [$story->requireId()]);

        self::assertNull($shown->linkHashOf($article->requireId(), $article->requireId()));
        self::assertSame(
            (new UrlNormalizer())->hash('https://outlet.example/storm'),
            $shown->linkHashOf($carded->requireId(), $carded->requireId()),
        );
    }

    public function testThreeTimelineCopiesOfOnePostCountAsOneSource(): void
    {
        $story = $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', [
            [$this->member($this->articleFeed('alpha')), 'alpha.example'],
            [$this->member($this->articleFeed('beta')), 'beta.example'],
            ...$this->copiesOf(self::POST, 'shared.example', 'home', 'discover', 'local'),
        ]);

        self::assertSame([[$story->requireId(), 3]], $this->shownStoriesAndSources());
    }

    public function testTwoAccountsSharingTheStoryAreTwoSources(): void
    {
        $story = $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', [
            [$this->member($this->articleFeed('alpha')), 'alpha.example'],
            [$this->member($this->articleFeed('beta')), 'beta.example'],
            ...$this->copiesOf(self::POST, 'shared.example', 'home'),
            ...$this->copiesOf(self::OTHER_POST, 'other.example', 'discover'),
        ]);

        self::assertSame([[$story->requireId(), 4]], $this->shownStoriesAndSources());
    }

    public function testACopyInAFeedTheReaderDoesNotFollowLeavesTheirCopyCounted(): void
    {
        $stranger = $this->fixtures->feed('stranger', SocialNetwork::Mastodon);
        $strangersCopy = $this->fixtures->article($stranger, 'Post', '2031-05-14 08:00:00', self::POST);
        $story = $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', [
            [$strangersCopy, 'shared.example'],
            [$this->member($this->articleFeed('alpha')), 'alpha.example'],
            [$this->member($this->articleFeed('beta')), 'beta.example'],
            ...$this->copiesOf(self::POST, 'shared.example', 'home'),
        ]);

        self::assertSame([[$story->requireId(), 3]], $this->shownStoriesAndSources());
    }

    public function testCopiesOfOnePostDoNotLiftAStoryOverTheMinimum(): void
    {
        $this->fiveStoriesOfThreeArticleFeeds();
        $copied = $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 11:00:00', [
            ...$this->copiesOf(self::POST, 'outlet.example', 'home', 'discover'),
            ...$this->copiesOf(self::OTHER_POST, 'other.example', 'local'),
        ]);

        self::assertNotContains($copied->requireId(), $this->shownStoryIds());
    }

    public function testStoriesRankByTheReadersFeedsNotByFeedsTheyDoNotFollow(): void
    {
        $unfollowed = array_map(
            fn (string $name): array => [$this->member($this->fixtures->feed($name)), $name . '.example'],
            ['one', 'two', 'three', 'four', 'five', 'six'],
        );
        $widerElsewhere = $this->storyOf([
            [$this->articleFeed('alpha'), 'alpha.example'],
            [$this->articleFeed('beta'), 'beta.example'],
        ]);
        $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 09:00:00', [
            ...$unfollowed,
            [$this->member($this->articleFeed('alpha-again')), 'alpha.example'],
            [$this->member($this->articleFeed('beta-again')), 'beta.example'],
        ]);
        $widerForTheReader = $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 08:00:00', [
            [$this->member($this->articleFeed('gamma')), 'gamma.example'],
            [$this->member($this->articleFeed('delta')), 'delta.example'],
            [$this->member($this->articleFeed('epsilon')), 'epsilon.example'],
        ]);

        self::assertSame(
            [$widerForTheReader->requireId(), $widerElsewhere->requireId()],
            \array_slice($this->shownStoryIds(), 0, 2),
        );
    }

    public function testPostsWithoutAStoryCardAreNoSources(): void
    {
        $story = $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', [
            [$this->member($this->articleFeed('alpha')), 'alpha.example'],
            [$this->member($this->articleFeed('beta')), 'beta.example'],
            [$this->member($this->socialFeed('home')), null],
            [$this->member($this->socialFeed('discover')), null],
        ]);

        self::assertSame([[$story->requireId(), 2]], $this->shownStoriesAndSources());
    }

    public function testEveryAccountSharingAStoryCardIsASourceWhateverSiteItShares(): void
    {
        $story = $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', [
            [$this->member($this->articleFeed('alpha')), 'alpha.example'],
            [$this->member($this->articleFeed('beta')), 'beta.example'],
            [$this->member($this->socialFeed('ann')), 'alpha.example'],
            [$this->member($this->socialFeed('ben')), 'other.example'],
            [$this->member($this->socialFeed('cat')), 'other.example'],
        ]);

        self::assertSame([[$story->requireId(), 5]], $this->shownStoriesAndSources());
    }

    public function testTwoPostsOfOneAccountAreOneSource(): void
    {
        $story = $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', [
            [$this->member($this->articleFeed('alpha')), 'alpha.example'],
            [$this->member($this->articleFeed('beta')), 'beta.example'],
            ...$this->copiesOf(self::POST, 'shared.example', 'home'),
            ...$this->copiesOf(self::SAME_POSTERS_OTHER_POST, 'other.example', 'discover'),
        ]);

        self::assertSame([[$story->requireId(), 3]], $this->shownStoriesAndSources());
    }

    /** @param list<array{Feed, ?string}> $feedsAndSites */
    private function storyOf(array $feedsAndSites): TrendingStory
    {
        return $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', array_map(
            fn (array $feedAndSite): array => [$this->member($feedAndSite[0]), $feedAndSite[1]],
            $feedsAndSites,
        ));
    }

    /**
     * One copy of the post in each named timeline the reader follows, by the account its URL names.
     *
     * @return list<array{Entry, string}>
     */
    private function copiesOf(string $postUrl, string $site, string ...$timelines): array
    {
        return array_values(array_map(
            function (string $timeline) use ($postUrl, $site): array {
                $copy = $this->fixtures->article($this->socialFeed($timeline), 'Post', '2031-05-14 09:00:00', $postUrl);
                $copy->setAuthor(new EntryAuthor('Poster', \dirname($postUrl)));

                return [$copy, $site];
            },
            $timelines,
        ));
    }

    private function fiveStoriesOfThreeArticleFeeds(): void
    {
        $feeds = [$this->articleFeed('alpha'), $this->articleFeed('beta'), $this->articleFeed('gamma')];
        for ($story = 0; $story < 5; ++$story) {
            $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', array_map(
                fn (Feed $feed): array => [$this->member($feed), null],
                $feeds,
            ));
        }
    }

    /** @return list<array{int, int}> each shown story with its source count */
    private function shownStoriesAndSources(): array
    {
        return array_map(
            static fn (RankedStoryModel $story): array => [$story->storyId, $story->outletCount],
            $this->ranked(),
        );
    }

    private function member(Feed $feed): Entry
    {
        return $this->fixtures->entry($feed, 'Story entry', '2031-05-14 09:00:00');
    }

    private function articleFeed(string $name): Feed
    {
        $feed = $this->fixtures->feed($name);
        $this->fixtures->subscribe($this->reader, $feed);

        return $feed;
    }

    private function socialFeed(string $name): Feed
    {
        $feed = $this->fixtures->feed($name, SocialNetwork::Mastodon);
        $this->fixtures->subscribe($this->reader, $feed);

        return $feed;
    }

    /** @return list<int> */
    private function shownStoryIds(): array
    {
        return RankedStoryModel::storyIdsOf($this->ranked());
    }

    /** @return list<RankedStoryModel> */
    private function ranked(): array
    {
        $readerWindows = new ReaderWindows(
            new TrendingEntryRepository($this->entityManager),
            $this->stories(),
            new NaiveUtcClock(new MockClock('2031-05-14 12:00:00', 'UTC')),
        );

        return $this->stories()->rankedForUser(
            $readerWindows->of($this->reader->requireId(), TrendingWindow::Day),
            TrendingStories::MAX_STORIES,
        );
    }

    private function stories(): TrendingStoryRepository
    {
        $stories = $this->entityManager->getRepository(TrendingStory::class);
        self::assertInstanceOf(TrendingStoryRepository::class, $stories);

        return $stories;
    }
}
