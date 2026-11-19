<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\EntryAuthor;
use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\Subscription;
use App\Entity\TrendingSnapshot;
use App\Entity\TrendingStory;
use App\Entity\User;
use App\Entity\Headline;
use App\Enum\FeedLanguage;
use App\Enum\SocialNetwork;
use App\Enum\TrendingWindow;
use App\Repository\TrendingStoryRepository;
use App\Service\Curation\Trending\Exception\TrendingStoryNotFoundException;
use App\Service\Curation\Trending\Model\RankedStoryModel;
use App\Service\Curation\Trending\Model\ReaderWindowModel;
use App\Service\Curation\Trending\Model\StoryHeadlineModel;
use App\Service\Curation\Trending\ReaderWindows;
use App\Service\Curation\Trending\TrendingStories;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;
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
    private int $weekStories = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new User('sites-reader@example.com', new \DateTimeImmutable('2031-01-01 00:00:00'));
        $this->entityManager->persist($this->reader);
        $this->fixtures = (new TrendingFixtures($this->entityManager))->ownedBy($this->reader);
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
            $this->stories()
                ->shownEntriesByStory(TrendingWindow::Day, [$story->requireId()])
                ->entryIdsByStory(),
        );
        self::assertSame(
            [],
            $this->stories()
                ->shownEntriesByStory(TrendingWindow::Day, [])
                ->entryIdsByStory(),
        );
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

        $shown = $this->stories()
            ->shownEntriesByStory(TrendingWindow::Day, [$story->requireId()]);

        self::assertNull($shown->linkHashOf($article->requireId(), $article->requireId()));
        self::assertSame(
            (new UrlNormalizer())->hash('https://outlet.example/storm'),
            $shown->linkHashOf($carded->requireId(), $carded->requireId()),
        );
    }

    public function testAShownPostIsKeyedByItsResolvedHash(): void
    {
        $carded = $this->member($this->socialFeed('ann'));
        $short = SharedLinks::of(
            $this->entityManager,
            new LinkCardContent('https://n.pr/x', 'Storm', null, null, 'n.pr'),
        );
        $this->entityManager->persist(new LinkCard($carded, 0, $short));
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE shared_link_resolution SET status = 'resolved', resolved_url = ?, resolved_url_hash = ?"
            . ' WHERE shared_link_id = ?',
            ['https://www.npr.org/storm', 'resolved-hash', $short->requireId()],
        );
        $story = $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', [
            [$carded, 'npr.org'],
        ]);

        $shown = $this->stories()
            ->shownEntriesByStory(TrendingWindow::Day, [$story->requireId()]);

        self::assertSame('resolved-hash', $shown->linkHashOf($carded->requireId(), $carded->requireId()));
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

    public function testCopiesOfOnePostDoNotLiftAStoryOverTheMinimum(): void
    {
        $this->fiveStoriesOfThreeArticleFeeds();
        $copied = $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 11:00:00', [
            ...$this->copiesOf(self::POST, 'outlet.example', 'home', 'discover'),
            ...$this->copiesOf(self::OTHER_POST, 'other.example', 'local'),
        ]);

        self::assertNotContains($copied->requireId(), $this->shownStoryIds());
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

    public function testAStoryPlacedInBothWindowsRanksInEachWithOnlyThatWindowsMembers(): void
    {
        $inBoth = [$this->member($this->articleFeed('alpha')), $this->member($this->articleFeed('beta'))];
        $members = [...$inBoth, $this->member($this->articleFeed('gamma'))];
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', ...$members);
        $this->fixtures->place(TrendingWindow::Day, $story, ...$inBoth);

        self::assertSame([[$story->requireId(), 2]], $this->rankedIn(TrendingWindow::Day, []));
        self::assertSame([[$story->requireId(), 3]], $this->rankedIn(TrendingWindow::Week, []));
    }

    public function testAMemberPlacedInNeitherWindowCountsInNeither(): void
    {
        $story = $this->fixtures->story(
            TrendingWindow::Week,
            '2031-05-14 10:00:00',
            $this->member($this->articleFeed('alpha')),
            $this->member($this->articleFeed('beta')),
        );
        $this->fixtures->join($story, '2031-05-14 11:00:00', $this->member($this->articleFeed('gamma')));

        self::assertSame([], $this->rankedIn(TrendingWindow::Day, []));
        self::assertSame([[$story->requireId(), 2]], $this->rankedIn(TrendingWindow::Week, []));
    }

    public function testAnEarlierCopyInAnotherStoryLeavesThisStorysCopyCounted(): void
    {
        $url = 'https://news.example.com/storm';
        $this->fixtures->story(
            TrendingWindow::Week,
            '2031-05-14 08:00:00',
            $this->fixtures->article($this->articleFeed('alpha'), 'Storm', '2031-05-14 08:00:00', $url),
        );
        $story = $this->fixtures->story(
            TrendingWindow::Week,
            '2031-05-14 10:00:00',
            $this->fixtures->article($this->articleFeed('beta'), 'Storm', '2031-05-14 09:00:00', $url),
            $this->member($this->articleFeed('gamma')),
        );

        self::assertSame([[$story->requireId(), 2]], $this->rankedIn(TrendingWindow::Week, []));
    }

    public function testAnEarlierCopyPlacedOnlyInTheWeekLeavesTheDaysCopyCounted(): void
    {
        $url = 'https://news.example.com/storm';
        $weekOnly = $this->fixtures->article($this->articleFeed('alpha'), 'Storm', '2031-05-14 08:00:00', $url);
        $inBoth = [
            $this->fixtures->article($this->articleFeed('beta'), 'Storm', '2031-05-14 09:00:00', $url),
            $this->member($this->articleFeed('gamma')),
        ];
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $weekOnly, ...$inBoth);
        $this->fixtures->place(TrendingWindow::Day, $story, ...$inBoth);

        self::assertSame([[$story->requireId(), 2]], $this->rankedIn(TrendingWindow::Day, []));
    }

    public function testAnEarlierCopyFromASocialFeedLeavesAnArticleFeedsCopyCounted(): void
    {
        $url = 'https://news.example.com/storm';
        $post = $this->fixtures->article($this->socialFeed('ann'), 'Storm', '2031-05-14 08:00:00', $url);
        $story = $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', [
            [$post, 'news.example.com'],
            [$this->fixtures->article($this->articleFeed('alpha'), 'Storm', '2031-05-14 09:00:00', $url), null],
            [$this->member($this->articleFeed('beta')), null],
        ]);

        self::assertSame([[$story->requireId(), 3]], $this->rankedIn(TrendingWindow::Day, []));
    }

    public function testTheWeekLeavesOutAStoryIdShownInTheDay(): void
    {
        $story = $this->twoFeedWeekStory();
        $other = $this->twoFeedWeekStory();

        self::assertSame([[$other->requireId(), 2]], $this->rankedIn(TrendingWindow::Week, [$story->requireId()]));
    }

    public function testAnEmptyDayLeavesNothingOutOfTheWeek(): void
    {
        $story = $this->twoFeedWeekStory();

        self::assertSame([[$story->requireId(), 2]], $this->rankedIn(TrendingWindow::Week, []));
    }

    public function testAHiddenStoryIsLeftOutAndAnotherUsersHideRowIsIgnored(): void
    {
        $story = $this->twoFeedWeekStory();
        $kept = $this->twoFeedWeekStory();

        $this->fixtures->hide($this->reader, $story);
        $this->fixtures->hide($this->fixtures->reader('sites-other@example.com'), $kept);

        self::assertSame([[$kept->requireId(), 2]], $this->rankedIn(TrendingWindow::Week, []));
    }

    public function testShownEntriesAndViewedReadOnlyTheWindowsMembers(): void
    {
        $inBoth = $this->member($this->articleFeed('alpha'));
        $weekOnly = $this->member($this->articleFeed('beta'));
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $inBoth, $weekOnly);
        $this->fixtures->place(TrendingWindow::Day, $story, $inBoth);
        $state = new EntryState($this->reader, $weekOnly);
        $state->markViewed(new \DateTimeImmutable('2031-05-14 11:00:00'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();
        $userId = $this->reader->requireId();
        $storyIds = [$story->requireId()];

        self::assertSame(
            [$story->requireId() => [$inBoth->requireId()]],
            $this->stories()->shownEntriesByStory(TrendingWindow::Day, $storyIds)->entryIdsByStory(),
        );
        self::assertSame(
            [$story->requireId() => [$inBoth->requireId(), $weekOnly->requireId()]],
            $this->stories()->shownEntriesByStory(TrendingWindow::Week, $storyIds)->entryIdsByStory(),
        );
        self::assertSame([], $this->stories()->viewedStoryIds($userId, TrendingWindow::Day, $storyIds));
        self::assertSame($storyIds, $this->stories()->viewedStoryIds($userId, TrendingWindow::Week, $storyIds));
        self::assertSame([], $this->stories()->viewedStoryIds($userId, TrendingWindow::Week, []));
    }

    public function testRankedForUserIsOnlyTheirStories(): void
    {
        $other = $this->fixtures->reader('sites-other-owner@example.com');
        $members = [$this->member($this->articleFeed('alpha')), $this->member($this->articleFeed('beta'))];
        $this->fixtures->subscribe($other, ...$this->entityManager->getRepository(Feed::class)->findAll());
        $own = $this->fixtures->storyOf($this->reader, TrendingWindow::Day, '2031-05-14 10:00:00', ...$members);
        $this->fixtures->storyOf($other, TrendingWindow::Day, '2031-05-14 11:00:00', ...$members);

        self::assertSame([[$own->requireId(), 2]], $this->rankedIn(TrendingWindow::Day, []));
    }

    public function testAMemberFromASwitchedOffFeedStillCounts(): void
    {
        $switchedOff = $this->articleFeed('gamma');
        $story = $this->fixtures->storyOf(
            $this->reader,
            TrendingWindow::Day,
            '2031-05-14 10:00:00',
            $this->member($this->articleFeed('alpha')),
            $this->member($this->articleFeed('beta')),
            $this->member($switchedOff),
        );
        $subscription = $this->entityManager->getRepository(Subscription::class)
            ->findOneBy(['user' => $this->reader, 'feed' => $switchedOff]);
        self::assertInstanceOf(Subscription::class, $subscription);

        $subscription->changeInclusions(new InclusionChange(trending: false));
        $this->entityManager->flush();

        self::assertSame([[$story->requireId(), 3]], $this->rankedIn(TrendingWindow::Day, []));
    }

    public function testRequireOwnedByRefusesAStoryThatIsGone(): void
    {
        $story = $this->twoFeedWeekStory();
        $this->stories()->requireOwnedBy($this->reader->requireId(), $story->requireId());

        $this->expectException(TrendingStoryNotFoundException::class);

        $this->stories()->requireOwnedBy($this->reader->requireId(), $story->requireId() + 1);
    }

    public function testRequireOwnedByRefusesAnotherUsersStory(): void
    {
        $story = $this->twoFeedWeekStory();
        $other = $this->fixtures->reader('sites-not-the-owner@example.com');

        $this->expectException(TrendingStoryNotFoundException::class);

        $this->stories()->requireOwnedBy($other->requireId(), $story->requireId());
    }

    private function twoFeedWeekStory(): TrendingStory
    {
        ++$this->weekStories;

        return $this->fixtures->story(
            TrendingWindow::Week,
            \sprintf('2031-05-14 %02d:00:00', 11 - $this->weekStories),
            $this->member($this->articleFeed('first-' . $this->weekStories)),
            $this->member($this->articleFeed('second-' . $this->weekStories)),
        );
    }

    /**
     * @param list<int> $shorterWindowStoryIds
     *
     * @return list<array{int, int}> each ranked story with its source count
     */
    private function rankedIn(TrendingWindow $window, array $shorterWindowStoryIds, ?User $reader = null): array
    {
        $ranked = $this->stories()->rankedForUser(
            new ReaderWindowModel(
                ($reader ?? $this->reader)->requireId(),
                $window,
                TrendingWindow::MULTIPLE_SOURCES,
                $shorterWindowStoryIds,
            ),
            TrendingStories::MAX_STORIES,
        );

        return array_map(
            static fn (RankedStoryModel $story): array => [$story->storyId, $story->outletCount],
            $ranked,
        );
    }

    public function testTitlesPreferTheLlmTitle(): void
    {
        $first = $this->storyOf([
            [$this->articleFeed('outlet'), 'outlet.example'],
            [$this->articleFeed('second'), 'second.example'],
        ]);
        $second = $this->storyOf([
            [$this->articleFeed('third'), 'third.example'],
            [$this->articleFeed('fourth'), 'fourth.example'],
        ]);
        $this->storeTitles($first, 'Hafenstreik in Hamburg', 'Hafenarbeiter in Hamburg streiken');
        $this->storeTitles($second, 'Hafenstreik in Hamburg', null);

        $titles = $this->entityManager->getRepository(TrendingStory::class)
            ->titlesOf([$first->requireId(), $second->requireId()]);

        self::assertSame(
            [
                $first->requireId() => 'Hafenarbeiter in Hamburg streiken',
                $second->requireId() => 'Hafenstreik in Hamburg',
            ],
            $titles,
        );
    }

    public function testTitlesLeaveOutAStoryWithoutTitles(): void
    {
        $untitled = $this->storyOf([
            [$this->articleFeed('outlet'), 'outlet.example'],
            [$this->articleFeed('second'), 'second.example'],
        ]);

        self::assertSame([], $this->entityManager->getRepository(TrendingStory::class)
            ->titlesOf([$untitled->requireId()]));
    }

    public function testTitlesOfNoStoriesRunNoQuery(): void
    {
        self::assertSame([], $this->entityManager->getRepository(TrendingStory::class)->titlesOf([]));
    }

    public function testMemberHeadlinesTakeOnePerOutletNewestFirst(): void
    {
        $members = [];
        $outlets = [];
        for ($outlet = 0; $outlet < 14; ++$outlet) {
            $outlets[$outlet] = $this->articleFeed('outlet-' . $outlet);
            $members[] = [$this->fixtures->entry(
                $outlets[$outlet],
                'Headline ' . $outlet,
                \sprintf('2031-05-14 %02d:00:00', $outlet),
            ), null];
        }
        $newer = $this->fixtures->entry($outlets[0], 'Spiegel: Newer headline', '2031-05-14 14:00:00');
        $members[] = [$newer, null];
        $members[] = [$this->fixtures->entry($this->socialFeed('ann'), 'A post', '2031-05-14 15:00:00'), null];
        $story = $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 16:00:00', $members);
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            "UPDATE feed SET title = 'DER SPIEGEL', language = 'de' WHERE id = ?",
            [$outlets[0]->requireId()],
        );
        $connection->executeStatement(
            "UPDATE entry SET summary = '<p>Der Hafen steht still.</p>' WHERE id = ?",
            [$newer->requireId()],
        );

        $headlines = $this->stories()->memberHeadlinesOf($story->requireId(), 12);

        self::assertSame(
            ['Spiegel: Newer headline', ...array_map(
                static fn (int $outlet): string => 'Headline ' . $outlet,
                range(13, 3),
            )],
            array_map(static fn (StoryHeadlineModel $headline): string => $headline->headline, $headlines),
        );
        self::assertEquals(
            new StoryHeadlineModel(
                'Spiegel: Newer headline',
                'Der Hafen steht still.',
                'DER SPIEGEL',
                FeedLanguage::German,
            ),
            $headlines[0],
        );
        self::assertSame('', $headlines[1]->snippet);
    }

    public function testADerivedHeadlinesSnippetStartsAfterIt(): void
    {
        $derived = $this->member($this->articleFeed('outlet'));
        $derived->setHeadline(Headline::derived('Der Hafen steht still'));
        $story = $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', [[$derived, null]]);
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE entry SET summary = 'Der Hafen steht still. Seit Montag.' WHERE id = ?",
            [$derived->requireId()],
        );

        $headlines = $this->stories()->memberHeadlinesOf($story->requireId(), 12);

        self::assertSame('Der Hafen steht still', $headlines[0]->headline);
        self::assertSame('. Seit Montag.', $headlines[0]->snippet);
    }

    public function testMemberHeadlinesOfAPostStoryReadItsCards(): void
    {
        $older = $this->fixtures->linkShare($this->socialFeed('ann'), '2031-05-14 08:00:00', new LinkCardContent(
            'https://outlet.example/strike',
            'Port strike halts traffic',
            '<p>The port is idle.</p>',
            null,
            'Outlet',
        ));
        $newer = $this->fixtures->linkShare($this->socialFeed('ben'), '2031-05-14 09:00:00', new LinkCardContent(
            'https://other.example/strike',
            'Dockers walk out',
            null,
            null,
            'Other',
        ));
        $alsoOlder = $this->fixtures->linkShare($this->socialFeed('cat'), '2031-05-14 07:00:00', new LinkCardContent(
            'https://outlet.example/strike',
            'Port strike halts traffic',
            '<p>The port is idle.</p>',
            null,
            'Outlet',
        ));
        $uncarded = $this->fixtures->linkShare($this->socialFeed('dan'), '2031-05-14 10:00:00', new LinkCardContent(
            'https://elsewhere.example/strike',
            'Not a story card',
            null,
            null,
            'Elsewhere',
        ));
        $story = $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 11:00:00', [
            [$older, 'outlet.example'],
            [$newer, 'other.example'],
            [$alsoOlder, 'outlet.example'],
            [$uncarded, null],
        ]);

        self::assertEquals(
            [
                new StoryHeadlineModel('Dockers walk out', ''),
                new StoryHeadlineModel('Port strike halts traffic', 'The port is idle.'),
            ],
            $this->stories()->memberHeadlinesOf($story->requireId(), 12),
        );
        self::assertCount(1, $this->stories()->memberHeadlinesOf($story->requireId(), 1));
    }

    public function testAStoryTitledBeforeSynopsesIsDueAgain(): void
    {
        $story = $this->storyOf([
            [$this->articleFeed('outlet'), 'outlet.example'],
            [$this->articleFeed('second'), 'second.example'],
        ]);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE trending_story SET llm_title = ?, llm_title_member_count = 0, llm_title_attempted_at = ?'
            . ' WHERE id = ?',
            ['Hafenstreik', '2031-05-14 08:00:00', $story->requireId()],
        );

        $due = $this->stories()->titleDueOf(
            $this->reader->requireId(),
            new \DateTimeImmutable('2031-05-14 11:00:00'),
            new \DateTimeImmutable('2031-05-07 00:00:00'),
        );

        self::assertSame([$story->requireId()], $due);
    }

    private function storeTitles(TrendingStory $story, ?string $fallbackTitle, ?string $llmTitle): void
    {
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE trending_story SET fallback_title = ?, llm_title = ? WHERE id = ?',
            [$fallbackTitle, $llmTitle, $story->requireId()],
        );
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
            $this->entityManager->getRepository(TrendingSnapshot::class),
            $this->stories(),
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
