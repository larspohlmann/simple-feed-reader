<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance;

use App\Enum\TrendingWindow;
use App\Service\Maintenance\DerivedViewSweeps;
use App\Service\Trending\Model\TrendingBuildReportModel;
use App\Tests\DbTestCase;
use App\Tests\Support\MembershipSweepFactory;
use App\Tests\Support\RecordingSavedSearchMatcher;
use App\Tests\Support\TrendingWiring;
use Symfony\Component\Clock\MockClock;

final class DerivedViewSweepsTest extends DbTestCase
{
    public function testRebuildsTheDueTrendingWindowsWhileTimeIsLeft(): void
    {
        $clock = new MockClock('2031-05-14 12:00:00', 'UTC');

        $report = $this->sweeps($clock)->sweep($clock->now()->modify('+1 second'));

        self::assertSame(
            [TrendingWindow::Day, TrendingWindow::Week],
            array_map(
                static fn (TrendingBuildReportModel $build): TrendingWindow => $build->window,
                $report->trending->builds,
            ),
        );
    }

    public function testLeavesTrendingForTheNextTickOnceTheDeadlineIsReached(): void
    {
        $clock = new MockClock('2031-05-14 12:00:00', 'UTC');

        $report = $this->sweeps($clock)->sweep($clock->now());

        self::assertSame([], $report->trending->builds);
    }

    private function sweeps(MockClock $clock): DerivedViewSweeps
    {
        return new DerivedViewSweeps(
            MembershipSweepFactory::fromContainer(
                self::getContainer(),
                $this->entityManager,
                new RecordingSavedSearchMatcher(),
                $clock,
            ),
            TrendingWiring::rebuild($this->entityManager, $clock),
            $clock,
        );
    }
}
