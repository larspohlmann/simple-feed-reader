<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\NetworkExclusion;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\TrendingStory;
use App\Entity\User;
use App\Enum\InclusionSurface;
use App\Enum\SocialNetwork;
use App\Enum\TrendingWindow;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnricher;
use App\Repository\ReadingSettingsRepository;
use App\Repository\TrendingEntryRepository;
use App\Repository\TrendingStoryRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Curation\Trending\Exception\TrendingStoryNotFoundException;
use App\Service\Curation\Trending\Model\RankedStoryModel;
use App\Service\Curation\Trending\Model\TrendingStoryCardModel;
use App\Service\Curation\Trending\ReaderWindows;
use App\Service\Curation\Trending\TrendingStories;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;
use App\Tests\Support\TrendingWiring;
use Symfony\Component\Clock\MockClock;

/** A feed switched out of Trending, by its own switch, a tag or its network, is no source of a story. */
final class TrendingInclusionTest extends DbTestCase
{
    private TrendingFixtures $fixtures;
    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new TrendingFixtures($this->entityManager);
        $this->reader = new User('trending-inclusion@example.com', new \DateTimeImmutable('2031-01-01 00:00:00'));
        $this->entityManager->persist($this->reader);
    }

    public function testAStoryWhoseSourcesAreSwitchedOutIsNeitherShownNorCounted(): void
    {
        $feeds = $this->articleFeeds('alpha', 'beta', 'gamma');
        $this->fixtures->subscribe($this->reader, ...$feeds);
        $this->storyOf($feeds);
        $this->fixtures->flush();
        foreach ($feeds as $feed) {
            $this->subscriptionTo($feed)->changeInclusions(new InclusionChange(trending: false));
        }
        $this->fixtures->flush();

        self::assertSame([], $this->storiesOfTheDay());
        self::assertSame(0, $this->stories()->unopenedFor($this->reader, TrendingWindow::Day)->unopened);
    }

    public function testASwitchedOutFeedIsNoSourceAndItsArticleIsNotListed(): void
    {
        [$alpha, $beta, $gamma, $delta] = $this->articleFeeds('alpha', 'beta', 'gamma', 'delta');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma, $delta);
        $story = $this->storyOf([$alpha, $beta, $gamma, $delta]);
        $this->fixtures->flush();
        $this->subscriptionTo($alpha)->changeInclusions(new InclusionChange(trending: false));
        $this->fixtures->flush();

        self::assertSame([3], self::outletCountsOf($this->rankedDay()));
        self::assertCount(3, $this->storyRepository()->articleEntryIdsByStory(
            $this->reader->requireId(),
            [$story->requireId()],
        )[$story->requireId()]);
    }

    public function testASwitchedOutFeedNoLongerHelpsAStoryReachTwoSources(): void
    {
        $feeds = $this->articleFeeds('alpha', 'beta');
        $this->fixtures->subscribe($this->reader, ...$feeds);
        $this->storyOf($feeds);
        $this->fixtures->flush();
        self::assertSame([2], self::outletCountsOf($this->rankedDay()));

        $this->subscriptionTo($feeds[0])->changeInclusions(new InclusionChange(trending: false));
        $this->fixtures->flush();

        self::assertSame([], $this->rankedDay());
    }

    public function testASwitchedOutFeedIsNotAnActiveFeedOfTheReader(): void
    {
        $feeds = $this->articleFeeds('alpha', 'beta');
        $this->fixtures->subscribe($this->reader, ...$feeds);
        $this->fixtures->entry($feeds[0], 'Alpha', '2031-05-14 08:00:00');
        $this->fixtures->entry($feeds[1], 'Beta', '2031-05-14 09:00:00');
        $this->fixtures->flush();
        $this->subscriptionTo($feeds[0])->changeInclusions(new InclusionChange(trending: false));
        $this->fixtures->flush();

        self::assertSame(1, (new TrendingEntryRepository($this->entityManager))->activeFeedCount(
            $this->reader->requireId(),
            new \DateTimeImmutable('2031-05-14 00:00:00'),
        ));
    }

    public function testAnEarlierCopyInASwitchedOutFeedLeavesTheLaterCopyCounted(): void
    {
        [$alpha, $beta, $gamma, $delta] = $this->articleFeeds('alpha', 'beta', 'gamma', 'delta');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma, $delta);
        $shared = 'https://news.example.com/switched-out-first';
        $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 10:00:00',
            $this->fixtures->article($alpha, 'Article', '2031-05-14 07:00:00', $shared),
            $this->fixtures->article($beta, 'Article', '2031-05-14 08:00:00', $shared),
            $this->fixtures->entry($gamma, 'Gamma', '2031-05-14 09:00:00'),
            $this->fixtures->entry($delta, 'Delta', '2031-05-14 10:00:00'),
        );
        $this->subscriptionTo($alpha)->changeInclusions(new InclusionChange(trending: false));
        $this->fixtures->flush();

        self::assertSame([3], self::outletCountsOf($this->rankedDay()));
    }

    public function testATagSwitchedOutOfTrendingTakesItsFeedsOut(): void
    {
        $tag = $this->tag('Noise');
        $tag->changeInclusions(new InclusionChange(trending: false));

        $this->assertTheTaggedFeedIsNoSource($tag);
    }

    public function testASubtagUnderAParentSwitchedOutOfTrendingTakesItsFeedsOut(): void
    {
        $parent = $this->tag('Noise');
        $parent->changeInclusions(new InclusionChange(trending: false));
        $child = $this->tag('Louder');
        $child->setParent($parent);

        $this->assertTheTaggedFeedIsNoSource($child);
    }

    public function testATagSwitchedOutOfAnotherSurfaceLeavesTrendingAlone(): void
    {
        $tag = $this->tag('Quiet');
        $tag->changeInclusions(new InclusionChange(allItems: false));
        $tag->changeInclusions(new InclusionChange(forYou: false));
        $tag->changeInclusions(new InclusionChange(savedSearches: false));
        [$alpha, $beta, $gamma, $delta] = $this->articleFeeds('alpha', 'beta', 'gamma', 'delta');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma, $delta);
        $this->storyOf([$alpha, $beta, $gamma, $delta]);
        $this->subscriptionTo($alpha)->addTag($tag);
        $this->fixtures->flush();

        self::assertSame([4], self::outletCountsOf($this->rankedDay()));
    }

    public function testANetworkSwitchedOutOfTrendingTakesItsFeedsOut(): void
    {
        [$alpha, $beta] = $this->articleFeeds('alpha', 'beta');
        $social = $this->fixtures->feed('ann', SocialNetwork::Bluesky);
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $social);
        $this->storyOf([$alpha, $beta, $social]);
        self::assertSame([3], self::outletCountsOf($this->rankedDay()));

        $this->entityManager->persist(
            new NetworkExclusion($this->reader, SocialNetwork::Bluesky, InclusionSurface::Trending),
        );
        $this->fixtures->flush();

        self::assertSame([2], self::outletCountsOf($this->rankedDay()));
    }

    public function testANetworkSwitchedOutOfAnotherSurfaceLeavesTrendingAlone(): void
    {
        [$alpha, $beta] = $this->articleFeeds('alpha', 'beta');
        $social = $this->fixtures->feed('ann', SocialNetwork::Bluesky);
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $social);
        $this->storyOf([$alpha, $beta, $social]);
        $this->entityManager->persist(
            new NetworkExclusion($this->reader, SocialNetwork::Bluesky, InclusionSurface::AllItems),
        );
        $this->fixtures->flush();

        self::assertSame([3], self::outletCountsOf($this->rankedDay()));
    }

    public function testAnEntryOfASwitchedOutFeedHoldsNoStoryTheReaderCanHide(): void
    {
        [$alpha, $beta, $gamma, $delta] = $this->articleFeeds('alpha', 'beta', 'gamma', 'delta');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma, $delta);
        $switchedOut = $this->fixtures->entry($alpha, 'Alpha', '2031-05-14 07:00:00');
        $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 10:00:00',
            $switchedOut,
            $this->fixtures->entry($beta, 'Beta', '2031-05-14 08:00:00'),
            $this->fixtures->entry($gamma, 'Gamma', '2031-05-14 09:00:00'),
            $this->fixtures->entry($delta, 'Delta', '2031-05-14 10:00:00'),
        );
        $this->subscriptionTo($alpha)->changeInclusions(new InclusionChange(trending: false));
        $this->fixtures->flush();

        $this->expectException(TrendingStoryNotFoundException::class);

        $this->storyRepository()->storyIdContaining(
            $this->readerWindows()->of($this->reader->requireId(), TrendingWindow::Day),
            $switchedOut->requireId(),
        );
    }

    public function testACopyOfTheLeadInASwitchedOutFeedIsNotShownOnTheCard(): void
    {
        [$alpha, $beta, $gamma, $delta] = $this->articleFeeds('alpha', 'beta', 'gamma', 'delta');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma, $delta);
        $url = 'https://news.example.com/lead-article';
        $lead = $this->fixtures->article($alpha, 'Lead', '2031-05-14 10:00:00', $url);
        $betaEntry = $this->fixtures->entry($beta, 'Beta', '2031-05-14 09:00:00');
        $gammaEntry = $this->fixtures->entry($gamma, 'Gamma', '2031-05-14 07:00:00');
        $this->fixtures->article($delta, 'Lead elsewhere', '2031-05-14 08:00:00', $url);
        $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $lead, $betaEntry, $gammaEntry);
        $this->subscriptionTo($delta)->changeInclusions(new InclusionChange(trending: false));
        $this->fixtures->flush();

        $stories = $this->storiesOfTheDay();

        self::assertSame(
            [$betaEntry->requireId(), $gammaEntry->requireId()],
            array_map(static fn (EntryListRow $row): int => $row->entry->requireId(), $stories[0]->lead->duplicates),
        );
    }

    private function assertTheTaggedFeedIsNoSource(Tag $tag): void
    {
        [$alpha, $beta, $gamma, $delta] = $this->articleFeeds('alpha', 'beta', 'gamma', 'delta');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma, $delta);
        $this->storyOf([$alpha, $beta, $gamma, $delta]);
        $this->subscriptionTo($alpha)->addTag($tag);
        $this->fixtures->flush();

        self::assertSame([3], self::outletCountsOf($this->rankedDay()));
    }

    private function tag(string $name): Tag
    {
        $tag = new Tag($this->reader, $name);
        $this->entityManager->persist($tag);

        return $tag;
    }

    private function subscriptionTo(Feed $feed): Subscription
    {
        $this->fixtures->flush();
        $subscription = $this->entityManager->getRepository(Subscription::class)
            ->findOneBy(['user' => $this->reader, 'feed' => $feed]);
        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription;
    }

    /**
     * @return list<RankedStoryModel>
     *
     * @phpstan-impure reads the snapshot the test keeps changing
     */
    private function rankedDay(): array
    {
        return $this->storyRepository()->rankedForUser(
            $this->readerWindows()->of($this->reader->requireId(), TrendingWindow::Day),
            TrendingStories::MAX_STORIES,
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

    /** @return list<TrendingStoryCardModel> */
    private function storiesOfTheDay(): array
    {
        return $this->stories()->forReader($this->reader->requireId(), TrendingWindow::Day)->stories;
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
    private function storyOf(array $feeds): TrendingStory
    {
        $lastSeen = '2031-05-14 10:00:00';
        $members = [];
        foreach ($feeds as $index => $feed) {
            $published = (new \DateTimeImmutable($lastSeen))->modify(\sprintf('-%d hours', $index));
            $members[] = $this->fixtures->entry($feed, 'Story entry', $published->format('Y-m-d H:i:s'));
        }

        return $this->fixtures->story(TrendingWindow::Day, $lastSeen, ...$members);
    }

    /** @return list<Feed> */
    private function articleFeeds(string ...$names): array
    {
        return array_values(array_map(fn (string $name): Feed => $this->fixtures->feed($name), $names));
    }
}
