<?php

declare(strict_types=1);

namespace App\Tests\Service\Trending;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\TrendingHiddenEntry;
use App\Entity\TrendingSnapshot;
use App\Entity\TrendingStory;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\TrendingWindow;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnricher;
use App\Repository\ReadingSettingsRepository;
use App\Repository\TrendingEntryRepository;
use App\Repository\TrendingStoryRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Trending\Model\RankedStoryModel;
use App\Service\Trending\Model\ReaderWindowModel;
use App\Service\Trending\Model\TrendingStoryCardModel;
use App\Service\Trending\ReaderWindows;
use App\Service\Trending\TrendingStories;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;
use App\Tests\Support\TrendingWiring;
use Symfony\Component\Clock\MockClock;

final class TrendingStoriesTest extends DbTestCase
{
    private TrendingFixtures $fixtures;
    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new TrendingFixtures($this->entityManager);
        $this->reader = $this->user('trending-reader@example.com');
    }

    public function testAStoryShowsOneArticlePerFeedLedByTheNewestWithAPicture(): void
    {
        [$alpha, $beta, $gamma] = $this->articleFeeds('alpha', 'beta', 'gamma');
        $social = $this->fixtures->feed('social', SocialNetwork::Bluesky);
        $stranger = $this->fixtures->feed('stranger');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma, $social);
        $alphaOld = $this->fixtures->entry($alpha, 'Alpha old', '2031-05-14 08:00:00');
        $alphaNew = $this->fixtures->entry($alpha, 'Alpha new', '2031-05-14 10:00:00');
        $betaPictured = $this->fixtures->entry($beta, 'Beta', '2031-05-14 09:00:00');
        $betaPictured->getImage()->storeUnverified('https://img.example.com/beta.jpg', 800, 450);
        $gammaNewest = $this->fixtures->entry($gamma, 'Gamma', '2031-05-14 11:00:00');
        $post = $this->fixtures->entry($social, 'Post', '2031-05-14 07:00:00');
        $unsubscribed = $this->fixtures->entry($stranger, 'Stranger', '2031-05-14 12:00:00');
        $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 12:00:00',
            $alphaOld,
            $alphaNew,
            $betaPictured,
            $gammaNewest,
            $post,
            $unsubscribed,
        );

        $stories = $this->storiesFor(TrendingWindow::Day);

        self::assertCount(1, $stories);
        self::assertSame($betaPictured->requireId(), $stories[0]->lead->entry->requireId());
        self::assertSame(
            [$gammaNewest->requireId(), $alphaNew->requireId()],
            self::idsOf($stories[0]->lead->duplicates),
        );
        self::assertSame(
            4,
            $stories[0]->story->outletCount,
            'the social account counts, the unsubscribed feed does not',
        );
        self::assertFalse($stories[0]->viewed);
    }

    public function testWithoutAPictureTheNewestArticleLeads(): void
    {
        [$alpha, $beta, $gamma] = $this->articleFeeds('alpha', 'beta', 'gamma');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma);
        $older = $this->fixtures->entry($alpha, 'Older', '2031-05-14 08:00:00');
        $newest = $this->fixtures->entry($beta, 'Newest', '2031-05-14 10:00:00');
        $middle = $this->fixtures->entry($gamma, 'Middle', '2031-05-14 09:00:00');
        $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $older, $newest, $middle);

        $stories = $this->storiesFor(TrendingWindow::Day);

        self::assertSame($newest->requireId(), $stories[0]->lead->entry->requireId());
        self::assertSame([$middle->requireId(), $older->requireId()], self::idsOf($stories[0]->lead->duplicates));
    }

    public function testTheDayNeedsThreeOfTheReadersFeedsAndRanksWidestThenNewest(): void
    {
        [$alpha, $beta, $gamma] = $this->articleFeeds('alpha', 'beta', 'gamma');
        $social = $this->fixtures->feed('social', SocialNetwork::Bluesky);
        $stranger = $this->fixtures->feed('stranger');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma, $social);
        $four = $this->storyOf('2031-05-14 09:00:00', [$alpha, $beta, $gamma, $social]);
        $threeOlder = $this->storyOf('2031-05-14 10:00:00', [$alpha, $beta, $social]);
        $threeNewer = $this->storyOf('2031-05-14 11:00:00', [$alpha, $gamma, $social]);
        $this->storyOf('2031-05-14 12:00:00', [$alpha, $beta, $stranger]);

        $stories = $this->storiesFor(TrendingWindow::Day);

        self::assertSame(
            [$four->requireId(), $threeNewer->requireId(), $threeOlder->requireId()],
            array_map(fn (TrendingStoryCardModel $card): int => $this->storyIdOf($card), $stories),
        );
        self::assertSame([4, 3, 3], array_map(
            static fn (TrendingStoryCardModel $card): int => $card->story->outletCount,
            $stories,
        ));
    }

    public function testAStoryWithoutAnArticleTheReaderCanSeeIsLeftOut(): void
    {
        $socials = [
            $this->fixtures->feed('ann', SocialNetwork::Bluesky),
            $this->fixtures->feed('ben', SocialNetwork::Mastodon),
            $this->fixtures->feed('cat', SocialNetwork::Bluesky),
        ];
        $this->fixtures->subscribe($this->reader, ...$socials);
        $this->storyOf('2031-05-14 10:00:00', $socials);

        self::assertSame([], $this->storyRepository()->rankedForUser(
            $this->readerWindows()->of($this->reader->requireId(), TrendingWindow::Day),
            TrendingStories::MAX_STORIES,
        ));
        self::assertSame([], $this->storiesFor(TrendingWindow::Day));
    }

    public function testAStoryNeedsTwoArticleFeedsTheReaderFollowsAndSocialOnesOnlyWidenIt(): void
    {
        [$alpha, $beta] = $this->articleFeeds('alpha', 'beta');
        $socials = [
            $this->fixtures->feed('ann', SocialNetwork::Bluesky),
            $this->fixtures->feed('ben', SocialNetwork::Mastodon),
        ];
        $this->fixtures->subscribe($this->reader, $alpha, $beta, ...$socials);
        $this->storyOf('2031-05-14 10:00:00', [$alpha, ...$socials]);

        self::assertSame([], $this->rankedDay());
        self::assertSame([], $this->storiesFor(TrendingWindow::Day));

        $covered = $this->storyOf('2031-05-14 11:00:00', [$alpha, $beta, ...$socials]);

        $ranked = $this->rankedDay();
        self::assertSame([$covered->requireId()], RankedStoryModel::storyIdsOf($ranked));
        self::assertSame([4], self::outletCountsOf($ranked));
    }

    public function testTheRankingIsCutAtTheLimit(): void
    {
        [$alpha, $beta, $gamma, $delta] = $this->articleFeeds('alpha', 'beta', 'gamma', 'delta');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma, $delta);
        $wider = $this->storyOf('2031-05-14 09:00:00', [$alpha, $beta, $gamma, $delta]);
        $this->storyOf('2031-05-14 10:00:00', [$alpha, $beta, $gamma]);

        $ranked = $this->rankedDay(1);

        self::assertSame([$wider->requireId()], RankedStoryModel::storyIdsOf($ranked));
        self::assertSame([4], self::outletCountsOf($ranked));
    }

    public function testTheListAndItsBadgeStopAtFifteenStories(): void
    {
        $feeds = $this->articleFeeds('alpha', 'beta', 'gamma');
        $this->fixtures->subscribe($this->reader, ...$feeds);
        for ($hour = 0; $hour < 16; ++$hour) {
            $this->storyOf(\sprintf('2031-05-14 %02d:30:00', $hour + 3), $feeds);
        }

        self::assertCount(15, $this->storiesFor(TrendingWindow::Day));
        self::assertSame(15, $this->stories()->unopenedFor($this->reader, TrendingWindow::Day)->unopened);
    }

    public function testOnlyArticlesOfFeedsTheReaderFollowsAreListedPerStory(): void
    {
        [$followed, $stranger] = $this->articleFeeds('followed', 'stranger');
        $social = $this->fixtures->feed('social', SocialNetwork::Bluesky);
        $this->fixtures->subscribe($this->reader, $followed, $social);
        $article = $this->fixtures->entry($followed, 'Followed', '2031-05-14 08:00:00');
        $story = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 10:00:00',
            $article,
            $this->fixtures->entry($stranger, 'Stranger', '2031-05-14 09:00:00'),
            $this->fixtures->entry($social, 'Post', '2031-05-14 07:00:00'),
        );
        $otherStory = $this->storyOf('2031-05-14 11:00:00', [$followed]);
        $userId = $this->reader->requireId();

        self::assertSame(
            [$story->requireId() => [$article->requireId()]],
            $this->storyRepository()->articleEntryIdsByStory($userId, [$story->requireId()]),
        );
        self::assertSame([$otherStory->requireId()], array_keys(
            $this->storyRepository()->articleEntryIdsByStory($userId, [$otherStory->requireId()]),
        ));
        self::assertSame([], $this->storyRepository()->articleEntryIdsByStory($userId, []));
    }

    public function testViewedStoriesAreLimitedToTheAskedStories(): void
    {
        [$alpha] = $this->articleFeeds('alpha');
        $this->fixtures->subscribe($this->reader, $alpha);
        $askedEntry = $this->fixtures->entry($alpha, 'Asked', '2031-05-14 08:00:00');
        $otherEntry = $this->fixtures->entry($alpha, 'Other', '2031-05-14 09:00:00');
        $asked = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $askedEntry);
        $this->fixtures->story(TrendingWindow::Day, '2031-05-14 11:00:00', $otherEntry);
        $this->markViewed($this->reader, $askedEntry);
        $this->markViewed($this->reader, $otherEntry);

        self::assertSame(
            [$asked->requireId()],
            $this->storyRepository()->viewedStoryIds($this->reader->requireId(), [$asked->requireId()]),
        );
    }

    public function testEveryOutletSharingOneArticleSurfacesOnTheCardNewestFirst(): void
    {
        [$alpha, $beta, $gamma, $delta] = $this->articleFeeds('alpha', 'beta', 'gamma', 'delta');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma, $delta);
        $shared = 'https://news.example.com/shared-article';
        $first = $this->fixtures->article($alpha, 'Shared', '2031-05-14 08:00:00', $shared);
        $second = $this->fixtures->article($beta, 'Shared', '2031-05-14 09:00:00', $shared);
        $other = $this->fixtures->article($gamma, 'Other', '2031-05-14 07:00:00', 'https://other.example.com/a');
        $third = $this->fixtures->article($delta, 'Third', '2031-05-14 06:00:00', 'https://third.example.com/a');
        $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $first, $second, $other, $third);

        $stories = $this->storiesFor(TrendingWindow::Day);

        self::assertCount(1, $stories);
        self::assertSame($second->requireId(), $stories[0]->lead->entry->requireId());
        self::assertSame(
            [$first->requireId(), $other->requireId(), $third->requireId()],
            self::idsOf($stories[0]->lead->duplicates),
        );
        self::assertSame([], $stories[0]->lead->duplicates[0]->duplicates);
    }

    public function testFeedsCarryingTheSameArticleCountAsOneSource(): void
    {
        [$alpha, $beta, $gamma, $delta] = $this->articleFeeds('alpha', 'beta', 'gamma', 'delta');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma, $delta);
        $shared = 'https://news.example.com/syndicated';
        $original = $this->fixtures->article($alpha, 'Original', '2031-05-14 08:00:00', $shared);
        $syndicated = $this->fixtures->article($beta, 'Syndicated', '2031-05-14 09:00:00', $shared);
        $narrower = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 11:00:00',
            $original,
            $syndicated,
            $this->fixtures->entry($gamma, 'Gamma', '2031-05-14 10:00:00'),
            $this->fixtures->entry($delta, 'Delta', '2031-05-14 11:00:00'),
        );
        $wider = $this->storyOf('2031-05-14 09:00:00', [$alpha, $beta, $gamma, $delta]);

        $ranked = $this->rankedDay();

        self::assertSame(
            [$wider->requireId(), $narrower->requireId()],
            RankedStoryModel::storyIdsOf($ranked),
            'the newer story is narrower once its syndicated copy is counted once',
        );
        self::assertSame([4, 3], self::outletCountsOf($ranked));
    }

    public function testTheSameArticleInTwoFeedsIsNotTwoArticleFeeds(): void
    {
        [$alpha, $beta] = $this->articleFeeds('alpha', 'beta');
        $socials = [
            $this->fixtures->feed('ann', SocialNetwork::Bluesky),
            $this->fixtures->feed('ben', SocialNetwork::Mastodon),
        ];
        $this->fixtures->subscribe($this->reader, $alpha, $beta, ...$socials);
        $shared = 'https://news.example.com/one-article';
        $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 10:00:00',
            $this->fixtures->article($alpha, 'Article', '2031-05-14 08:00:00', $shared),
            $this->fixtures->article($beta, 'Article', '2031-05-14 09:00:00', $shared),
            $this->fixtures->entry($socials[0], 'Post', '2031-05-14 09:30:00'),
            $this->fixtures->entry($socials[1], 'Post', '2031-05-14 10:00:00'),
        );

        self::assertSame([], $this->rankedDayStoryIdsOf($this->reader));
    }

    public function testEverySocialPostCountsEvenWhereTwoAccountsCarryTheSameOne(): void
    {
        [$alpha, $beta] = $this->articleFeeds('alpha', 'beta');
        $socials = [
            $this->fixtures->feed('ann', SocialNetwork::Bluesky),
            $this->fixtures->feed('ben', SocialNetwork::Bluesky),
        ];
        $this->fixtures->subscribe($this->reader, $alpha, $beta, ...$socials);
        $post = 'https://bsky.app/profile/someone/post/one';
        $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 10:00:00',
            $this->fixtures->entry($alpha, 'Alpha', '2031-05-14 07:00:00'),
            $this->fixtures->entry($beta, 'Beta', '2031-05-14 08:00:00'),
            $this->fixtures->article($socials[0], 'Post', '2031-05-14 09:00:00', $post),
            $this->fixtures->article($socials[1], 'Post', '2031-05-14 10:00:00', $post),
        );

        $ranked = $this->rankedDay();

        self::assertSame([4], self::outletCountsOf($ranked));
    }

    public function testAnEarlierCopyInAFeedTheReaderDoesNotFollowLeavesTheirCopyCounted(): void
    {
        [$alpha, $beta, $gamma] = $this->articleFeeds('alpha', 'beta', 'gamma');
        $stranger = $this->fixtures->feed('stranger');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma);
        $shared = 'https://news.example.com/elsewhere-first';
        $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 10:00:00',
            $this->fixtures->article($stranger, 'Article', '2031-05-14 07:00:00', $shared),
            $this->fixtures->article($alpha, 'Article', '2031-05-14 08:00:00', $shared),
            $this->fixtures->entry($beta, 'Beta', '2031-05-14 09:00:00'),
            $this->fixtures->entry($gamma, 'Gamma', '2031-05-14 10:00:00'),
        );

        $ranked = $this->rankedDay();

        self::assertSame([3], self::outletCountsOf($ranked));
    }

    public function testACopyOfTheLeadFromAFeedOutsideTheStoryShowsOnTheCardButIsNoSource(): void
    {
        [$alpha, $beta, $gamma, $delta] = $this->articleFeeds('alpha', 'beta', 'gamma', 'delta');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma, $delta);
        $url = 'https://news.example.com/lead-article';
        $lead = $this->fixtures->article($alpha, 'Lead', '2031-05-14 10:00:00', $url);
        $betaEntry = $this->fixtures->entry($beta, 'Beta', '2031-05-14 09:00:00');
        $gammaEntry = $this->fixtures->entry($gamma, 'Gamma', '2031-05-14 07:00:00');
        $copy = $this->fixtures->article($delta, 'Lead elsewhere', '2031-05-14 08:00:00', $url);
        $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $lead, $betaEntry, $gammaEntry);

        $stories = $this->storiesFor(TrendingWindow::Day);

        self::assertSame($lead->requireId(), $stories[0]->lead->entry->requireId());
        self::assertSame(
            [$betaEntry->requireId(), $copy->requireId(), $gammaEntry->requireId()],
            self::idsOf($stories[0]->lead->duplicates),
        );
        self::assertSame(3, $stories[0]->story->outletCount);
    }

    public function testACopyOfTheLeadFromAFeedAlreadyInTheStoryOrFromTheLeadsOwnFeedDoesNotRepeat(): void
    {
        [$alpha, $beta, $gamma] = $this->articleFeeds('alpha', 'beta', 'gamma');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma);
        $url = 'https://news.example.com/lead-article';
        $lead = $this->fixtures->article($alpha, 'Lead', '2031-05-14 10:00:00', $url);
        $betaInStory = $this->fixtures->entry($beta, 'Beta', '2031-05-14 08:00:00');
        $gammaInStory = $this->fixtures->entry($gamma, 'Gamma', '2031-05-14 07:00:00');
        $betaCopy = $this->fixtures->article($beta, 'Lead at beta', '2031-05-14 09:00:00', $url);
        $this->fixtures->article($alpha, 'Lead again', '2031-05-14 06:00:00', $url . '?utm_source=feed');
        $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $lead, $betaInStory, $gammaInStory);

        $stories = $this->storiesFor(TrendingWindow::Day);

        self::assertSame($lead->requireId(), $stories[0]->lead->entry->requireId());
        self::assertSame(
            [$betaCopy->requireId(), $gammaInStory->requireId()],
            self::idsOf($stories[0]->lead->duplicates),
        );
        self::assertSame(3, $stories[0]->story->outletCount);
    }

    public function testAStoryIsViewedOnceTheReaderOpenedAnyOfItsEntriesASocialOneIncluded(): void
    {
        [$alpha, $beta] = $this->articleFeeds('alpha', 'beta');
        $social = $this->fixtures->feed('social', SocialNetwork::Bluesky);
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $social);
        $post = $this->fixtures->entry($social, 'Post', '2031-05-14 07:00:00');
        $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 10:00:00',
            $this->fixtures->entry($alpha, 'Alpha', '2031-05-14 08:00:00'),
            $this->fixtures->entry($beta, 'Beta', '2031-05-14 09:00:00'),
            $post,
        );
        $this->markViewed($this->reader, $post);

        self::assertTrue($this->storiesFor(TrendingWindow::Day)[0]->viewed);
    }

    public function testAnotherReadersViewDoesNotCount(): void
    {
        [$alpha, $beta, $gamma] = $this->articleFeeds('alpha', 'beta', 'gamma');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma);
        $opened = $this->fixtures->entry($alpha, 'Alpha', '2031-05-14 08:00:00');
        $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 10:00:00',
            $opened,
            $this->fixtures->entry($beta, 'Beta', '2031-05-14 09:00:00'),
            $this->fixtures->entry($gamma, 'Gamma', '2031-05-14 09:30:00'),
        );
        $this->markViewed($this->user('someone-else@example.com'), $opened);

        self::assertFalse($this->storiesFor(TrendingWindow::Day)[0]->viewed);
    }

    public function testTheWeekNeedsFiveOfTheReadersFeedsAndShowsNoDayStory(): void
    {
        $feeds = $this->articleFeeds('alpha', 'beta', 'gamma', 'delta', 'omega');
        $this->fixtures->subscribe($this->reader, ...$feeds);
        $five = $this->storyOf('2031-05-14 10:00:00', $feeds, TrendingWindow::Week);
        $this->storyOf('2031-05-14 11:00:00', \array_slice($feeds, 0, 4), TrendingWindow::Week);
        $this->storyOf('2031-05-14 12:00:00', $feeds);

        $stories = $this->storiesFor(TrendingWindow::Week);

        self::assertSame([$five->requireId()], array_map(
            fn (TrendingStoryCardModel $card): int => $this->storyIdOf($card),
            $stories,
        ));
    }

    public function testAWeekStorySharingAnEntryWithAShownDayStoryIsLeftOutOfTheListAndTheBadge(): void
    {
        $feeds = $this->articleFeeds('alpha', 'beta', 'gamma', 'delta', 'omega');
        $this->fixtures->subscribe($this->reader, ...$feeds);
        $shared = $this->fixtures->entry($feeds[0], 'Shared', '2031-05-14 11:00:00');
        $this->storySharing($shared, TrendingWindow::Day, \array_slice($feeds, 1, 2));
        $this->storySharing($shared, TrendingWindow::Week, \array_slice($feeds, 1));
        $earlier = $this->storyOf('2031-05-12 10:00:00', $feeds, TrendingWindow::Week);

        self::assertSame([$earlier->requireId()], $this->storyIdsFor(TrendingWindow::Week));
        self::assertSame(1, $this->stories()->unopenedFor($this->reader, TrendingWindow::Week)->unopened);
    }

    public function testADayStoryTheReaderIsNotShownLeavesTheWeekAlone(): void
    {
        $feeds = $this->articleFeeds('alpha', 'beta', 'gamma', 'delta', 'omega');
        $this->fixtures->subscribe($this->reader, ...$feeds);
        $inANarrowDayStory = $this->fixtures->entry($feeds[0], 'Narrow', '2031-05-14 11:00:00');
        $this->storySharing($inANarrowDayStory, TrendingWindow::Day, \array_slice($feeds, 1, 1));
        $narrowWeek = $this->storySharing($inANarrowDayStory, TrendingWindow::Week, \array_slice($feeds, 1));
        $inAHiddenDayStory = $this->fixtures->entry($feeds[0], 'Hidden', '2031-05-14 10:00:00');
        $hiddenDayMember = $this->fixtures->entry($feeds[1], 'Hidden', '2031-05-14 09:00:00');
        $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 10:00:00',
            $inAHiddenDayStory,
            $hiddenDayMember,
            $this->fixtures->entry($feeds[2], 'Hidden', '2031-05-14 09:00:00'),
        );
        $this->hideFor($this->reader, $hiddenDayMember);
        $hiddenWeek = $this->storySharing($inAHiddenDayStory, TrendingWindow::Week, \array_slice($feeds, 1));

        self::assertSame([], $this->rankedDayStoryIdsOf($this->reader));
        self::assertSame(
            [$narrowWeek->requireId(), $hiddenWeek->requireId()],
            $this->storyIdsFor(TrendingWindow::Week),
        );
    }

    public function testTheWeekCapCountsAfterTheExclusion(): void
    {
        $feeds = $this->articleFeeds('alpha', 'beta', 'gamma', 'delta', 'omega');
        $this->fixtures->subscribe($this->reader, ...$feeds);
        $shared = $this->fixtures->entry($feeds[0], 'Shared', '2031-05-14 11:00:00');
        $this->storySharing($shared, TrendingWindow::Day, \array_slice($feeds, 1, 2));
        $excluded = $this->storySharing($shared, TrendingWindow::Week, \array_slice($feeds, 1));
        for ($hour = 0; $hour < 15; ++$hour) {
            $this->storyOf(\sprintf('2031-05-13 %02d:30:00', $hour + 5), $feeds, TrendingWindow::Week);
        }

        $shown = $this->storyIdsFor(TrendingWindow::Week);

        self::assertCount(15, $shown);
        self::assertNotContains($excluded->requireId(), $shown);
        self::assertSame(15, $this->stories()->unopenedFor($this->reader, TrendingWindow::Week)->unopened);
    }

    public function testThePageCarriesTheWindowAndWhenItsSnapshotWasBuilt(): void
    {
        $this->entityManager->persist(new TrendingSnapshot(
            TrendingWindow::Day,
            new \DateTimeImmutable('2031-05-14 12:00:00'),
            10,
            0,
            5,
            1_000,
        ));
        $this->entityManager->flush();

        $day = $this->stories()->forReader($this->reader->requireId(), TrendingWindow::Day);
        $week = $this->stories()->forReader($this->reader->requireId(), TrendingWindow::Week);

        self::assertSame(TrendingWindow::Day, $day->window);
        self::assertSame('2031-05-14T12:00:00+00:00', $day->builtAt?->format(\DateTimeInterface::ATOM));
        self::assertNull($week->builtAt);
    }

    public function testAStoryHoldingAnEntryTheReaderHidStaysOutEvenOnceItGrows(): void
    {
        [$alpha, $beta, $gamma, $delta] = $this->articleFeeds('alpha', 'beta', 'gamma', 'delta');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma, $delta);
        $hidden = $this->fixtures->entry($alpha, 'Hidden', '2031-05-14 10:00:00');
        $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 10:00:00',
            $hidden,
            $this->fixtures->entry($beta, 'Hidden', '2031-05-14 09:00:00'),
            $this->fixtures->entry($gamma, 'Hidden', '2031-05-14 08:00:00'),
        );
        $untouched = $this->storyOf('2031-05-14 07:00:00', [$alpha, $beta, $gamma]);
        $this->hideFor($this->reader, $hidden);

        self::assertSame([$untouched->requireId()], $this->rankedDayStoryIdsOf($this->reader));

        $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 11:00:00',
            $hidden,
            $this->fixtures->entry($beta, 'Grown', '2031-05-14 11:00:00'),
            $this->fixtures->entry($gamma, 'Grown', '2031-05-14 11:00:00'),
            $this->fixtures->entry($delta, 'Grown', '2031-05-14 11:00:00'),
        );

        self::assertSame([$untouched->requireId()], $this->rankedDayStoryIdsOf($this->reader));

        $other = $this->user('trending-other-reader@example.com');
        $this->fixtures->subscribe($other, $alpha, $beta, $gamma, $delta);
        $this->fixtures->flush();

        self::assertCount(3, $this->rankedDayStoryIdsOf($other));
    }

    public function testAThreeOutletStoryShowsToAFewActiveFeedsAndNotToOneHundredAndOne(): void
    {
        [$alpha, $beta, $gamma] = $this->articleFeeds('alpha', 'beta', 'gamma');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma);
        $story = $this->storyOf('2031-05-14 10:00:00', [$alpha, $beta, $gamma]);

        self::assertSame([$story->requireId()], $this->rankedDayStoryIdsOf($this->reader));
        self::assertSame(1, $this->stories()->unopenedFor($this->reader, TrendingWindow::Day)->unopened);

        $this->followFeedsPostingAt(98, '2031-05-13 12:00:00');

        self::assertSame([], $this->rankedDayStoryIdsOf($this->reader));
        self::assertSame([], $this->storiesFor(TrendingWindow::Day));
        self::assertSame(0, $this->stories()->unopenedFor($this->reader, TrendingWindow::Day)->unopened);
    }

    public function testFeedsThatPostedOnlyBeforeTheWindowAreNotActive(): void
    {
        [$alpha, $beta, $gamma] = $this->articleFeeds('alpha', 'beta', 'gamma');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma);
        $story = $this->storyOf('2031-05-14 10:00:00', [$alpha, $beta, $gamma]);

        $this->followFeedsPostingAt(98, '2031-05-13 11:59:59');

        self::assertSame([$story->requireId()], $this->rankedDayStoryIdsOf($this->reader));
    }

    public function testTheActiveFeedsAreTheReadersFeedsWithAnEntryInTheWindowEachCountedOnce(): void
    {
        [$twice, $stale, $stranger] = $this->articleFeeds('twice', 'stale', 'stranger');
        $this->fixtures->subscribe($this->reader, $twice, $stale);
        $this->fixtures->entry($twice, 'First', '2031-05-14 08:00:00');
        $this->fixtures->entry($twice, 'Second', '2031-05-14 09:00:00');
        $this->fixtures->entry($stale, 'Stale', '2031-05-13 08:00:00');
        $this->fixtures->entry($stranger, 'Stranger', '2031-05-14 08:00:00');
        $this->fixtures->flush();

        self::assertSame(1, (new TrendingEntryRepository($this->entityManager))->activeFeedCount(
            $this->reader->requireId(),
            new \DateTimeImmutable('2031-05-14 00:00:00'),
        ));
    }

    /** Each new feed posts once at $effectiveDate; the clock stands at 2031-05-14 12:00, so a day reaches 05-13 12:00. */
    private function followFeedsPostingAt(int $count, string $effectiveDate): void
    {
        for ($index = 0; $index < $count; ++$index) {
            $feed = $this->fixtures->feed(\sprintf('active-%d', $index));
            $this->fixtures->subscribe($this->reader, $feed);
            $this->fixtures->entry($feed, 'Elsewhere', $effectiveDate);
        }
        $this->fixtures->flush();
    }

    private function hideFor(User $user, Entry $entry): void
    {
        $this->entityManager->persist(
            new TrendingHiddenEntry($user, $entry, new \DateTimeImmutable('2031-05-14 12:00:00')),
        );
        $this->entityManager->flush();
    }

    /**
     * @return list<int>
     *
     * @phpstan-impure reads the snapshot the test keeps changing
     */
    private function rankedDayStoryIdsOf(User $user): array
    {
        return RankedStoryModel::storyIdsOf($this->rankedDay(TrendingStories::MAX_STORIES, $user));
    }

    /**
     * @return list<RankedStoryModel>
     *
     * @phpstan-impure reads the snapshot the test keeps changing
     */
    private function rankedDay(int $limit = TrendingStories::MAX_STORIES, ?User $user = null): array
    {
        return $this->storyRepository()->rankedForUser(
            $this->dayOf(($user ?? $this->reader)->requireId()),
            $limit,
        );
    }

    /**
     * @param list<RankedStoryModel> $ranked
     *
     * @return list<int>
     */
    private static function outletCountsOf(array $ranked): array
    {
        return array_map(static fn (RankedStoryModel $story): int => $story->outletCount, $ranked);
    }

    /** @return list<int> */
    private function storyIdsFor(TrendingWindow $window): array
    {
        return array_map(
            static fn (TrendingStoryCardModel $card): int => $card->story->storyId,
            $this->storiesFor($window),
        );
    }

    /**
     * $shared, the newest member, and one older entry per other feed, last seen when $shared was published.
     *
     * @param list<Feed> $otherFeeds
     */
    private function storySharing(Entry $shared, TrendingWindow $window, array $otherFeeds): TrendingStory
    {
        $lastSeen = $shared->getEffectiveDate()->format('Y-m-d H:i:s');
        $members = [$shared];
        foreach ($otherFeeds as $index => $feed) {
            $published = $shared->getEffectiveDate()->modify(\sprintf('-%d hours', $index + 1));
            $members[] = $this->fixtures->entry($feed, 'Story entry', $published->format('Y-m-d H:i:s'));
        }

        return $this->fixtures->story($window, $lastSeen, ...$members);
    }

    /** @return list<TrendingStoryCardModel> */
    private function storiesFor(TrendingWindow $window): array
    {
        return $this->stories()->forReader($this->reader->requireId(), $window)->stories;
    }

    private function stories(): TrendingStories
    {
        $entryList = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $entryList);
        $enricher = self::getContainer()->get(EntryListRowEnricher::class);
        self::assertInstanceOf(EntryListRowEnricher::class, $enricher);
        $readingSettings = self::getContainer()->get(ReadingSettingsRepository::class);
        self::assertInstanceOf(ReadingSettingsRepository::class, $readingSettings);

        return new TrendingStories(
            $this->storyRepository(),
            $this->readerWindows(),
            TrendingWiring::snapshots($this->entityManager),
            $entryList,
            $enricher,
            $readingSettings,
        );
    }

    private function dayOf(int $userId): ReaderWindowModel
    {
        return $this->readerWindows()->of($userId, TrendingWindow::Day);
    }

    private function readerWindows(): ReaderWindows
    {
        return new ReaderWindows(
            new TrendingEntryRepository($this->entityManager),
            $this->storyRepository(),
            new NaiveUtcClock(new MockClock('2031-05-14 12:00:00', 'UTC')),
        );
    }

    private function storyRepository(): TrendingStoryRepository
    {
        $storyRepository = $this->entityManager->getRepository(TrendingStory::class);
        self::assertInstanceOf(TrendingStoryRepository::class, $storyRepository);

        return $storyRepository;
    }

    /**
     * One article or post per feed, each published at the story's last-seen time minus its position in hours.
     *
     * @param list<Feed> $feeds
     */
    private function storyOf(
        string $lastSeen,
        array $feeds,
        TrendingWindow $window = TrendingWindow::Day,
    ): TrendingStory {
        $members = [];
        foreach ($feeds as $index => $feed) {
            $published = (new \DateTimeImmutable($lastSeen))->modify(\sprintf('-%d hours', $index));
            $members[] = $this->fixtures->entry($feed, 'Story entry', $published->format('Y-m-d H:i:s'));
        }

        return $this->fixtures->story($window, $lastSeen, ...$members);
    }

    /** @return list<Feed> */
    private function articleFeeds(string ...$names): array
    {
        return array_values(array_map(fn (string $name) => $this->fixtures->feed($name), $names));
    }

    private function storyIdOf(TrendingStoryCardModel $card): int
    {
        $storyId = $this->entityManager->getConnection()->fetchOne(
            'SELECT story_id FROM trending_story_entry WHERE entry_id = ?',
            [$card->lead->entry->requireId()],
        );

        self::assertIsNumeric($storyId);

        return (int) $storyId;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2031-01-01 00:00:00'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function markViewed(User $user, Entry $entry): void
    {
        $state = new EntryState($user, $entry);
        $state->markViewed(new \DateTimeImmutable('2031-05-14 11:00:00'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<int>
     */
    private static function idsOf(array $rows): array
    {
        return array_map(static fn (EntryListRow $row): int => $row->entry->requireId(), $rows);
    }
}
