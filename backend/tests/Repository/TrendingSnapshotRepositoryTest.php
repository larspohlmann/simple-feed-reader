<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\TrendingSnapshot;
use App\Enum\TrendingWindow;
use App\Repository\TrendingSnapshotRepository;
use App\Service\Trending\Model\StoryMemberModel;
use App\Service\Trending\Model\TrendingBuildReportModel;
use App\Service\Trending\Model\TrendingStoryModel;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;

final class TrendingSnapshotRepositoryTest extends DbTestCase
{
    public function testReplacingAWindowWritesItsStoriesMembersAndSnapshot(): void
    {
        [$one, $two] = $this->twoEntries();

        $this->snapshots()->replace(self::report(TrendingWindow::Day, 2), [self::story($one, $two)]);
        $this->entityManager->clear();

        self::assertSame(1, $this->storyCount(TrendingWindow::Day));
        self::assertSame(2, $this->memberCount());
        $snapshot = $this->entityManager->find(TrendingSnapshot::class, 'day');
        self::assertInstanceOf(TrendingSnapshot::class, $snapshot);
        self::assertSame(7, $snapshot->getEntryCount());
        self::assertSame(2, $snapshot->getStoryCount());
        self::assertSame(311, $snapshot->getDurationMilliseconds());
        self::assertSame(4_096_000, $snapshot->getPeakMemoryBytes());
    }

    public function testReplacingADayLeavesTheWeekAndDropsTheOldDay(): void
    {
        [$one, $two] = $this->twoEntries();
        $this->snapshots()->replace(self::report(TrendingWindow::Day, 1), [self::story($one, $two)]);
        $this->snapshots()->replace(self::report(TrendingWindow::Week, 1), [self::story($one, $two)]);

        $this->snapshots()->replace(self::report(TrendingWindow::Day, 0), []);

        self::assertSame(0, $this->storyCount(TrendingWindow::Day));
        self::assertSame(1, $this->storyCount(TrendingWindow::Week));
        self::assertSame(2, $this->memberCount(), 'the dropped day story took its members with it');
    }

    public function testPurgingAnEntryRemovesItsStoryLink(): void
    {
        [$one, $two] = $this->twoEntries();
        $this->snapshots()->replace(self::report(TrendingWindow::Day, 1), [self::story($one, $two)]);

        $this->entityManager->getConnection()->executeStatement('DELETE FROM entry WHERE id = ?', [$one]);

        self::assertSame(1, $this->memberCount());
    }

    public function testBuiltAtIsReadBackAsUtcPerWindow(): void
    {
        $this->snapshots()->replace(self::report(TrendingWindow::Week, 0), []);

        $builtAt = $this->snapshots()->builtAtByWindow();

        self::assertSame(['week'], array_keys($builtAt));
        self::assertSame('2031-05-14T12:00:00+00:00', $builtAt['week']->format(\DateTimeInterface::ATOM));
    }

    /** @return array{int, int} */
    private function twoEntries(): array
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $one = $fixtures->entry($fixtures->feed('one'), 'One', '2031-05-14 08:00:00');
        $two = $fixtures->entry($fixtures->feed('two'), 'Two', '2031-05-14 09:00:00');
        $fixtures->flush();

        return [$one->requireId(), $two->requireId()];
    }

    private static function story(int $one, int $two): TrendingStoryModel
    {
        return new TrendingStoryModel(
            [new StoryMemberModel($one, 101), new StoryMemberModel($two, 202)],
            new \DateTimeImmutable('2031-05-14 08:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2031-05-14 09:00:00', new \DateTimeZone('UTC')),
        );
    }

    private static function report(TrendingWindow $window, int $storyCount): TrendingBuildReportModel
    {
        return new TrendingBuildReportModel(
            $window,
            new \DateTimeImmutable('2031-05-14 12:00:00', new \DateTimeZone('UTC')),
            7,
            $storyCount,
            311,
            4_096_000,
        );
    }

    private function snapshots(): TrendingSnapshotRepository
    {
        $repository = $this->entityManager->getRepository(TrendingSnapshot::class);
        self::assertInstanceOf(TrendingSnapshotRepository::class, $repository);

        return $repository;
    }

    private function storyCount(TrendingWindow $window): int
    {
        $count = $this->entityManager->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM trending_story WHERE time_window = ?', [$window->value]);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function memberCount(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM trending_story_entry');
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
