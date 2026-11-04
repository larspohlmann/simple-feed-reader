<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance\MaintenanceSweep;

use App\Service\Trending\Model\TrendingRebuildReportModel;
use App\Service\Trending\Model\TrendingBuildReportModel;
use App\Enum\TrendingWindow;
use App\Service\Maintenance\MaintenanceSweep\TrendingTickSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingWiring;
use Symfony\Component\Clock\MockClock;

final class TrendingTickSweepTest extends DbTestCase
{
    public function testRebuildsTheDueWindowsWhileTimeIsLeft(): void
    {
        $clock = new MockClock('2031-05-14 12:00:00', 'UTC');

        $report = $this->sweep($clock)->run($clock->now()->modify('+1 second'));

        self::assertSame(
            [TrendingWindow::Day, TrendingWindow::Week],
            array_map(static fn (TrendingBuildReportModel $build): TrendingWindow => $build->window, $report->builds),
        );
    }

    public function testLeavesTrendingForTheNextTickOnceTheDeadlineIsReached(): void
    {
        $clock = new MockClock('2031-05-14 12:00:00', 'UTC');

        $report = $this->sweep($clock)->run($clock->now());

        self::assertEquals(TrendingRebuildReportModel::none(), $report);
    }

    private function sweep(MockClock $clock): TrendingTickSweep
    {
        return new TrendingTickSweep(TrendingWiring::rebuild($this->entityManager, $clock));
    }
}
