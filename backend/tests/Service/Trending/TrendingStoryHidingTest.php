<?php

declare(strict_types=1);

namespace App\Tests\Service\Trending;

use App\Entity\Entry;
use App\Entity\TrendingHiddenEntry;
use App\Entity\TrendingStory;
use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Repository\TrendingEntryRepository;
use App\Repository\TrendingHiddenEntryRepository;
use App\Repository\TrendingStoryRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Trending\Exception\TrendingStoryNotFoundException;
use App\Service\Trending\ReaderWindows;
use App\Service\Trending\TrendingStoryHiding;
use App\Tests\DbTestCase;
use App\Tests\Support\InterruptingClock;
use App\Tests\Support\TrendingFixtures;
use Symfony\Component\Clock\MockClock;

final class TrendingStoryHidingTest extends DbTestCase
{
    private const string NOW = '2031-05-14 12:00:00';

    private TrendingFixtures $fixtures;
    private User $reader;

    /** @var list<Entry> */
    private array $members;

    private TrendingStory $story;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new TrendingFixtures($this->entityManager);
        $this->reader = new User('trending-hider@example.com', new \DateTimeImmutable('2031-01-01 00:00:00'));
        $this->entityManager->persist($this->reader);
        $feeds = [$this->fixtures->feed('alpha'), $this->fixtures->feed('beta'), $this->fixtures->feed('gamma')];
        $this->fixtures->subscribe($this->reader, ...$feeds);
        $this->members = array_map(
            fn ($feed): Entry => $this->fixtures->entry($feed, 'Member', '2031-05-14 10:00:00'),
            $feeds,
        );
        $this->story = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', ...$this->members);
    }

    public function testAHideRacingARebuildHidesTheStoryTheRebuildRegroupedTheEntryInto(): void
    {
        $hiding = $this->hidingInterruptedBy(function (): void {
            $this->deleteStory();
            $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', ...$this->members);
        });

        $hiding->hide($this->reader->requireId(), TrendingWindow::Day, $this->members[0]->requireId());

        self::assertSame(3, $this->hiddenCount());
    }

    public function testAHideRacingARebuildThatDroppedTheEntryFindsNoStory(): void
    {
        $hiding = $this->hidingInterruptedBy($this->deleteStory(...));

        $this->expectException(TrendingStoryNotFoundException::class);

        $hiding->hide($this->reader->requireId(), TrendingWindow::Day, $this->members[0]->requireId());
    }

    /** @param \Closure(): void $rebuild */
    private function hidingInterruptedBy(\Closure $rebuild): TrendingStoryHiding
    {
        $stories = $this->entityManager->getRepository(TrendingStory::class);
        self::assertInstanceOf(TrendingStoryRepository::class, $stories);
        $hiddenEntries = $this->entityManager->getRepository(TrendingHiddenEntry::class);
        self::assertInstanceOf(TrendingHiddenEntryRepository::class, $hiddenEntries);

        return new TrendingStoryHiding(
            $stories,
            new ReaderWindows(
                new TrendingEntryRepository($this->entityManager),
                new NaiveUtcClock(new MockClock(self::NOW, 'UTC')),
            ),
            $hiddenEntries,
            new NaiveUtcClock(new InterruptingClock(new \DateTimeImmutable(self::NOW), $rebuild)),
        );
    }

    private function deleteStory(): void
    {
        $connection = $this->entityManager->getConnection();
        $storyId = $this->story->requireId();
        $connection->executeStatement('DELETE FROM trending_story_entry WHERE story_id = ?', [$storyId]);
        $connection->executeStatement('DELETE FROM trending_story WHERE id = ?', [$storyId]);
    }

    private function hiddenCount(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM trending_hidden_entry');
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
