<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
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

final class TrendingStoryRepositoryTest extends DbTestCase
{
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
            $this->stories()->shownEntryIdsByStory($this->reader->requireId(), [$story->requireId()]),
        );
        self::assertSame([], $this->stories()->shownEntryIdsByStory($this->reader->requireId(), []));
    }

    /** @param list<array{Feed, ?string}> $feedsAndSites */
    private function storyOf(array $feedsAndSites): TrendingStory
    {
        return $this->fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', array_map(
            fn (array $feedAndSite): array => [$this->member($feedAndSite[0]), $feedAndSite[1]],
            $feedsAndSites,
        ));
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
        $readerWindows = new ReaderWindows(
            new TrendingEntryRepository($this->entityManager),
            $this->stories(),
            new NaiveUtcClock(new MockClock('2031-05-14 12:00:00', 'UTC')),
        );

        return RankedStoryModel::storyIdsOf($this->stories()->rankedForUser(
            $readerWindows->of($this->reader->requireId(), TrendingWindow::Day),
            TrendingStories::MAX_STORIES,
        ));
    }

    private function stories(): TrendingStoryRepository
    {
        $stories = $this->entityManager->getRepository(TrendingStory::class);
        self::assertInstanceOf(TrendingStoryRepository::class, $stories);

        return $stories;
    }
}
