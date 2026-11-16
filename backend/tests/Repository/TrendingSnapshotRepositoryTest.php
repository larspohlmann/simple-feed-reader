<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\TrendingSnapshot;
use App\Enum\TrendingWindow;
use App\Repository\TrendingSnapshotRepository;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use App\Tests\DbTestCase;

final class TrendingSnapshotRepositoryTest extends DbTestCase
{
    public function testRecordingASnapshotReplacesItsWindowsRowOnly(): void
    {
        $this->snapshots()->recordSnapshot(self::report(TrendingWindow::Week, 4));
        $this->snapshots()->recordSnapshot(self::report(TrendingWindow::Day, 1));

        $this->snapshots()->recordSnapshot(self::report(TrendingWindow::Day, 2));
        $this->entityManager->clear();

        $snapshot = $this->entityManager->find(TrendingSnapshot::class, 'day');
        self::assertInstanceOf(TrendingSnapshot::class, $snapshot);
        self::assertSame(7, $snapshot->getEntryCount());
        self::assertSame(2, $snapshot->getStoryCount());
        self::assertSame(311, $snapshot->getDurationMilliseconds());
        self::assertSame(4_096_000, $snapshot->getPeakMemoryBytes());
        self::assertSame(4, $this->entityManager->find(TrendingSnapshot::class, 'week')?->getStoryCount());
    }

    public function testBuiltAtIsReadBackAsUtcPerWindow(): void
    {
        $this->snapshots()->recordSnapshot(self::report(TrendingWindow::Week, 0));

        $lastBuilds = $this->snapshots()->lastBuilds();

        self::assertNull($lastBuilds->builtAt(TrendingWindow::Day));
        self::assertSame(
            '2031-05-14T12:00:00+00:00',
            $lastBuilds->builtAt(TrendingWindow::Week)?->format(\DateTimeInterface::ATOM),
        );
    }

    public function testDurationsAreReadBackPerWindow(): void
    {
        $this->snapshots()->recordSnapshot(self::report(TrendingWindow::Week, 0));

        $lastBuilds = $this->snapshots()->lastBuilds();

        self::assertSame(5_000, $lastBuilds->expectedDurationMilliseconds(TrendingWindow::Day), 'never built');
        self::assertSame(311, $lastBuilds->expectedDurationMilliseconds(TrendingWindow::Week));
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
            new StoryMatchModel([], [], 0),
            0,
        );
    }

    private function snapshots(): TrendingSnapshotRepository
    {
        $repository = $this->entityManager->getRepository(TrendingSnapshot::class);
        self::assertInstanceOf(TrendingSnapshotRepository::class, $repository);

        return $repository;
    }
}
