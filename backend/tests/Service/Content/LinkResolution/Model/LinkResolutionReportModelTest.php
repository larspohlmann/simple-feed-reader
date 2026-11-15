<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\LinkResolution\Model;

use App\Service\Content\LinkResolution\Model\LinkLandingModel;
use App\Service\Content\LinkResolution\Model\LinkResolutionReportModel;
use PHPUnit\Framework\TestCase;

final class LinkResolutionReportModelTest extends TestCase
{
    public function testEachLandingIsCountedUnderItsOutcome(): void
    {
        $report = LinkResolutionReportModel::none()
            ->counted(LinkLandingModel::resolved('https://a.example/a', 'hash'), 0.5)
            ->counted(LinkLandingModel::unchanged(), 0.25)
            ->counted(LinkLandingModel::unresolved(), 1.0)
            ->counted(LinkLandingModel::retryable(), 2.0)
            ->counted(LinkLandingModel::unchanged(), 0.25);

        self::assertSame(
            ['considered' => 5, 'resolved' => 1, 'unchanged' => 2, 'unresolved' => 1, 'deferred' => 1,
                'seconds' => 4.0],
            $report->toLogContext(),
        );
    }

    public function testTheLogRoundsSecondsToTheMillisecond(): void
    {
        $report = LinkResolutionReportModel::none()->counted(LinkLandingModel::unchanged(), 1.23456);

        self::assertSame(1.235, $report->toLogContext()['seconds']);
    }

    public function testSecondsPerLinkAveragesTheConsideredLinks(): void
    {
        $report = LinkResolutionReportModel::none()
            ->counted(LinkLandingModel::unchanged(), 1.0)
            ->counted(LinkLandingModel::unresolved(), 2.0);

        self::assertSame(1.5, $report->secondsPerLink());
        self::assertSame(0.0, LinkResolutionReportModel::none()->secondsPerLink());
    }

    public function testReportsAddUp(): void
    {
        $first = LinkResolutionReportModel::none()->counted(LinkLandingModel::unchanged(), 1.5);
        $second = LinkResolutionReportModel::none()
            ->counted(LinkLandingModel::resolved('https://a.example/a', 'hash'), 0.5)
            ->counted(LinkLandingModel::retryable(), 1.0)
            ->counted(LinkLandingModel::unresolved(), 1.0);

        self::assertEquals(new LinkResolutionReportModel(4, 1, 1, 1, 1, 4.0), $first->plus($second));
    }
}
