<?php

declare(strict_types=1);

namespace App\Tests\Service\Trending;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\TrendingSnapshot;
use App\Entity\TrendingStory;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\TrendingWindow;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnricher;
use App\Repository\TrendingStoryRepository;
use App\Service\Trending\Model\TrendingStoryCardModel;
use App\Service\Trending\TrendingStories;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;
use App\Tests\Support\TrendingWiring;

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
        self::assertSame([$gammaNewest->requireId(), $alphaNew->requireId()], self::idsOf($stories[0]->others));
        self::assertSame(4, $stories[0]->outletCount, 'the social account counts, the unsubscribed feed does not');
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
        self::assertSame([$middle->requireId(), $older->requireId()], self::idsOf($stories[0]->others));
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
            static fn (TrendingStoryCardModel $card): int => $card->outletCount,
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
            $this->reader->requireId(),
            TrendingWindow::Day,
            TrendingStories::MAX_STORIES,
        ));
        self::assertSame([], $this->storiesFor(TrendingWindow::Day));
    }

    public function testEveryOutletSharingOneArticleSurfacesOnTheCardNewestFirst(): void
    {
        [$alpha, $beta, $gamma] = $this->articleFeeds('alpha', 'beta', 'gamma');
        $this->fixtures->subscribe($this->reader, $alpha, $beta, $gamma);
        $shared = 'https://news.example.com/shared-article';
        $first = $this->fixtures->article($alpha, 'Shared', '2031-05-14 08:00:00', $shared);
        $second = $this->fixtures->article($beta, 'Shared', '2031-05-14 09:00:00', $shared);
        $other = $this->fixtures->article($gamma, 'Other', '2031-05-14 07:00:00', 'https://other.example.com/a');
        $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $first, $second, $other);

        $stories = $this->storiesFor(TrendingWindow::Day);

        self::assertCount(1, $stories);
        self::assertSame($second->requireId(), $stories[0]->lead->entry->requireId());
        self::assertSame([$first->requireId(), $other->requireId()], self::idsOf($stories[0]->others));
        self::assertSame([], $stories[0]->lead->duplicates);
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

        return new TrendingStories(
            $this->storyRepository(),
            TrendingWiring::snapshots($this->entityManager),
            $entryList,
            $enricher,
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
