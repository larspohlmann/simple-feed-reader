<?php

declare(strict_types=1);

namespace App\Tests\Service\Trending;

use App\Entity\TrendingSnapshot;
use App\Enum\TrendingWindow;
use App\Service\Trending\Model\TrendingBuildReportModel;
use App\Service\Trending\Model\TrendingRebuildReportModel;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingWiring;
use Symfony\Component\Clock\MockClock;

final class TrendingRebuildTest extends DbTestCase
{
    public function testBuildsEveryWindowNeverBuilt(): void
    {
        self::assertSame([TrendingWindow::Day, TrendingWindow::Week], self::windowsOf($this->rebuildDue()));
    }

    public function testRebuildsAWindowOnceItsSnapshotIsAsOldAsItsInterval(): void
    {
        $this->snapshotBuiltAt(TrendingWindow::Day, '2031-05-14 11:50:00');
        $this->snapshotBuiltAt(TrendingWindow::Week, '2031-05-14 11:00:00');

        self::assertSame([TrendingWindow::Day, TrendingWindow::Week], self::windowsOf($this->rebuildDue()));
    }

    public function testLeavesASnapshotYoungerThanItsIntervalAlone(): void
    {
        $this->snapshotBuiltAt(TrendingWindow::Day, '2031-05-14 11:50:01');
        $this->snapshotBuiltAt(TrendingWindow::Week, '2031-05-14 11:00:01');

        self::assertSame([], self::windowsOf($this->rebuildDue()));
    }

    public function testEachWindowKeepsItsOwnInterval(): void
    {
        $this->snapshotBuiltAt(TrendingWindow::Day, '2031-05-14 11:40:00');
        $this->snapshotBuiltAt(TrendingWindow::Week, '2031-05-14 11:40:00');

        self::assertSame([TrendingWindow::Day], self::windowsOf($this->rebuildDue()));
    }

    private function rebuildDue(): TrendingRebuildReportModel
    {
        $clock = new MockClock('2031-05-14 12:00:00', 'UTC');

        return TrendingWiring::rebuild($this->entityManager, $clock)->rebuildDue();
    }

    private function snapshotBuiltAt(TrendingWindow $window, string $builtAt): void
    {
        $this->entityManager->persist(new TrendingSnapshot($window, new \DateTimeImmutable($builtAt), 0, 0, 0, 0));
        $this->entityManager->flush();
    }

    /** @return list<TrendingWindow> */
    private static function windowsOf(TrendingRebuildReportModel $report): array
    {
        return array_map(
            static fn (TrendingBuildReportModel $build): TrendingWindow => $build->window,
            $report->builds,
        );
    }
}
