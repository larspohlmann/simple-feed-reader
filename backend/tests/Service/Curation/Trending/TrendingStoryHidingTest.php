<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Entity\TrendingStory;
use App\Entity\TrendingStoryHide;
use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Repository\TrendingStoryHideRepository;
use App\Repository\TrendingStoryRepository;
use App\Service\Curation\Trending\Exception\TrendingStoryNotFoundException;
use App\Service\Curation\Trending\TrendingStoryHiding;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;
use Symfony\Component\Clock\MockClock;

final class TrendingStoryHidingTest extends DbTestCase
{
    private TrendingFixtures $fixtures;
    private User $reader;
    private TrendingStory $story;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new User('trending-hider@example.com', new \DateTimeImmutable('2031-01-01 00:00:00'));
        $this->entityManager->persist($this->reader);
        $this->fixtures = (new TrendingFixtures($this->entityManager))->ownedBy($this->reader);
        $this->story = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 10:00:00',
            $this->fixtures->entry($this->fixtures->feed('alpha'), 'Member', '2031-05-14 10:00:00'),
        );
    }

    public function testHidingRecordsTheStoryAtTheClocksNow(): void
    {
        $this->hiding('2031-05-14 12:00:00')->hide($this->reader->requireId(), $this->story->requireId());

        self::assertSame([[$this->story->requireId(), '2031-05-14 12:00:00']], $this->hides());
    }

    public function testHidingAgainKeepsTheFirstTime(): void
    {
        $this->hiding('2031-05-14 12:00:00')->hide($this->reader->requireId(), $this->story->requireId());
        $this->hiding('2031-05-14 13:00:00')->hide($this->reader->requireId(), $this->story->requireId());

        self::assertSame([[$this->story->requireId(), '2031-05-14 12:00:00']], $this->hides());
    }

    public function testAStoryThatIsGoneIsNotFoundAndHidesNothing(): void
    {
        try {
            $this->hiding('2031-05-14 12:00:00')->hide($this->reader->requireId(), $this->story->requireId() + 1);
            self::fail('an unknown story must not be hidden');
        } catch (TrendingStoryNotFoundException) {
        }

        self::assertSame([], $this->hides());
    }

    private function hiding(string $now): TrendingStoryHiding
    {
        $stories = $this->entityManager->getRepository(TrendingStory::class);
        self::assertInstanceOf(TrendingStoryRepository::class, $stories);
        $hides = $this->entityManager->getRepository(TrendingStoryHide::class);
        self::assertInstanceOf(TrendingStoryHideRepository::class, $hides);

        return new TrendingStoryHiding($stories, $hides, new NaiveUtcClock(new MockClock($now, 'UTC')));
    }

    /** @return list<array{int, string}> each hidden story with when it was hidden */
    private function hides(): array
    {
        /** @var list<array{story_id: int|string, hidden_at: string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT story_id, hidden_at FROM trending_story_hide WHERE user_id = ?',
            [$this->reader->requireId()],
        );

        return array_map(static fn (array $row): array => [(int) $row['story_id'], $row['hidden_at']], $rows);
    }
}
