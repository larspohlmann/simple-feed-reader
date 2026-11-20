<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\TrendingSnapshot;
use App\Entity\TrendingStory;
use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Repository\TrendingSnapshotRepository;
use App\Repository\TrendingStoryRepository;
use App\Service\Curation\Trending\ReaderWindows;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;

final class ReaderWindowsTest extends DbTestCase
{
    private const int SNAPSHOT_ACTIVE_FEEDS = 200;

    private TrendingFixtures $fixtures;
    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = (new TrendingFixtures($this->entityManager))->reader('reader-windows@example.com');
        $this->fixtures = (new TrendingFixtures($this->entityManager))->ownedBy($this->reader);
    }

    public function testRelaxedMinimumUsesTheSnapshotsActiveFeedCount(): void
    {
        $minimum = TrendingWindow::Day->minimumOutletsFor(self::SNAPSHOT_ACTIVE_FEEDS);
        $feeds = array_map(
            fn (int $index): Feed => $this->fixtures->feed('outlet-' . $index),
            range(1, $minimum),
        );
        for ($story = 0; $story < TrendingWindow::STORIES_WORTH_SHOWING; ++$story) {
            $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', ...array_map(
                fn (Feed $feed): Entry => $this->fixtures->entry($feed, 'Story entry', '2031-05-14 09:00:00'),
                $feeds,
            ));
        }
        $this->entityManager->persist(new TrendingSnapshot(
            $this->reader,
            TrendingWindow::Day,
            new \DateTimeImmutable('2031-05-14 10:00:00'),
            100,
            self::SNAPSHOT_ACTIVE_FEEDS,
            TrendingWindow::STORIES_WORTH_SHOWING,
            5,
            1_000,
        ));
        $this->entityManager->flush();

        self::assertGreaterThan(TrendingWindow::Day->minSubscribedFeeds(), $minimum);
        $reader = $this->readerWindows()->loadWindow($this->reader->requireId(), TrendingWindow::Day);
        self::assertSame($minimum, $reader->minimumOutlets);
    }

    private function readerWindows(): ReaderWindows
    {
        $snapshots = $this->entityManager->getRepository(TrendingSnapshot::class);
        self::assertInstanceOf(TrendingSnapshotRepository::class, $snapshots);
        $stories = $this->entityManager->getRepository(TrendingStory::class);
        self::assertInstanceOf(TrendingStoryRepository::class, $stories);

        return new ReaderWindows($snapshots, $stories);
    }
}
