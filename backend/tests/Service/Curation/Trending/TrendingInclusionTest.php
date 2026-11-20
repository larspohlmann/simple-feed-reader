<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\NetworkExclusion;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\TrendingSnapshot;
use App\Entity\TrendingStory;
use App\Entity\User;
use App\Enum\InclusionSurface;
use App\Enum\SocialNetwork;
use App\Enum\TrendingWindow;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\ReadingSettingsRepository;
use App\Repository\TrendingEntryRepository;
use App\Repository\TrendingStoryRepository;
use App\Service\Curation\Trending\Model\RankedStoryModel;
use App\Service\Curation\Trending\Model\StoryArticleModel;
use App\Service\Curation\Trending\Model\TrendingStoryCardModel;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Service\Curation\Trending\ReaderWindows;
use App\Service\Curation\Trending\TrendingStories;
use App\Service\Library\EntryList\EntryListRowEnricher;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;
use App\Tests\Support\TrendingWiring;

/** A feed switched out of Trending, by its own switch, a tag or its network, is left out of the next build. */
final class TrendingInclusionTest extends DbTestCase
{
    private TrendingFixtures $fixtures;
    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new User('trending-inclusion@example.com', new \DateTimeImmutable('2031-01-01 00:00:00'));
        $this->entityManager->persist($this->reader);
        $this->fixtures = (new TrendingFixtures($this->entityManager))->ownedBy($this->reader);
    }

    public function testAStoryWhoseSourcesAreSwitchedOutAfterTheBuildStaysAsBuilt(): void
    {
        $feeds = $this->articleFeeds('alpha', 'beta', 'gamma');
        $this->storyOf($feeds);
        $this->fixtures->flush();
        foreach ($feeds as $feed) {
            $this->subscriptionTo($feed)->changeInclusions(new InclusionChange(trending: false));
        }
        $this->fixtures->flush();

        self::assertSame([3], self::outletCountsOf($this->rankedDay()));
        self::assertSame(1, $this->stories()->tallyUnopened($this->reader, TrendingWindow::Day)->unopened);
    }

    public function testASwitchedOutFeedIsNotBuilt(): void
    {
        [$alpha, $beta] = $this->articleFeeds('alpha', 'beta');
        $this->fixtures->entry($alpha, 'Switched out', '2031-05-14 08:00:00');
        $kept = $this->fixtures->entry($beta, 'Kept', '2031-05-14 09:00:00');
        $this->subscriptionTo($alpha)->changeInclusions(new InclusionChange(trending: false));
        $this->fixtures->flush();

        self::assertSame([$kept->requireId()], $this->windowEntryIds());
    }

    public function testASwitchedOutFeedIsNotAnActiveFeedOfTheReader(): void
    {
        $feeds = $this->articleFeeds('alpha', 'beta');
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

    public function testOnlyTheLowestIdCopyCountsEvenWhenItsFeedWasSwitchedOutAfterTheBuild(): void
    {
        [$alpha, $beta, $gamma, $delta] = $this->articleFeeds('alpha', 'beta', 'gamma', 'delta');
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
        [$tagged] = $this->articleFeeds('tagged');
        $entry = $this->fixtures->entry($tagged, 'Tagged', '2031-05-14 09:00:00');
        $this->subscriptionTo($tagged)->addTag($tag);
        $this->fixtures->flush();

        self::assertSame([$entry->requireId()], $this->windowEntryIds());
    }

    public function testANetworkSwitchedOutOfTrendingTakesItsFeedsOut(): void
    {
        [$alpha] = $this->articleFeeds('alpha');
        $article = $this->fixtures->entry($alpha, 'Article', '2031-05-14 09:00:00');
        $this->fixtures->entry($this->fixtures->feed('ann', SocialNetwork::Bluesky), 'Post', '2031-05-14 10:00:00');
        $this->entityManager->persist(
            new NetworkExclusion($this->reader, SocialNetwork::Bluesky, InclusionSurface::Trending),
        );
        $this->fixtures->flush();

        self::assertSame([$article->requireId()], $this->windowEntryIds());
    }

    public function testANetworkSwitchedOutOfAnotherSurfaceLeavesTrendingAlone(): void
    {
        $post = $this->fixtures->entry(
            $this->fixtures->feed('ann', SocialNetwork::Bluesky),
            'Post',
            '2031-05-14 10:00:00',
        );
        $this->entityManager->persist(
            new NetworkExclusion($this->reader, SocialNetwork::Bluesky, InclusionSurface::AllItems),
        );
        $this->fixtures->flush();

        self::assertSame([$post->requireId()], $this->windowEntryIds());
    }

    public function testACopyOfTheLeadInASwitchedOutFeedIsNotShownOnTheCard(): void
    {
        [$alpha, $beta, $gamma, $delta] = $this->articleFeeds('alpha', 'beta', 'gamma', 'delta');
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
            array_map(static fn (EntryListRow $row): int => $row->entry->requireId(), self::otherRowsOf($stories[0])),
        );
    }

    private function assertTheTaggedFeedIsNoSource(Tag $tag): void
    {
        [$tagged, $untagged] = $this->articleFeeds('tagged', 'untagged');
        $this->fixtures->entry($tagged, 'Tagged', '2031-05-14 08:00:00');
        $kept = $this->fixtures->entry($untagged, 'Untagged', '2031-05-14 09:00:00');
        $this->subscriptionTo($tagged)->addTag($tag);
        $this->fixtures->flush();

        self::assertSame([$kept->requireId()], $this->windowEntryIds());
    }

    /** @return list<int> the entries the reader's next build reads */
    private function windowEntryIds(): array
    {
        return array_map(
            static fn (WindowEntryModel $entry): int => $entry->id,
            iterator_to_array(
                (new TrendingEntryRepository($this->entityManager))->windowEntries(
                    $this->reader->requireId(),
                    new \DateTimeImmutable('2031-05-14 00:00:00', new \DateTimeZone('UTC')),
                ),
                false,
            ),
        );
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
            $this->readerWindows()->loadWindow($this->reader->requireId(), TrendingWindow::Day),
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
            TrendingWiring::follows($this->entityManager),
        );
    }

    private function readerWindows(): ReaderWindows
    {
        return new ReaderWindows(
            $this->entityManager->getRepository(TrendingSnapshot::class),
            $this->storyRepository(),
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

    /** @return list<EntryListRow> the rows of the card's other articles, as the API lists them */
    private static function otherRowsOf(TrendingStoryCardModel $card): array
    {
        return array_map(
            static fn (StoryArticleModel $article): EntryListRow => $article->row,
            $card->articles->others,
        );
    }
}
