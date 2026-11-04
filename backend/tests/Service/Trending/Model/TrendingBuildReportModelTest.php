<?php

declare(strict_types=1);

namespace App\Tests\Service\Trending\Model;

use App\Enum\TrendingWindow;
use App\Service\Trending\Model\TrendingBuildReportModel;
use PHPUnit\Framework\TestCase;

final class TrendingBuildReportModelTest extends TestCase
{
    public function testTheLogContextNamesEveryFigureOfTheBuild(): void
    {
        $report = new TrendingBuildReportModel(
            TrendingWindow::Week,
            new \DateTimeImmutable('2031-05-14 12:00:00'),
            1_200,
            14,
            25_000,
            64_000_000,
        );

        self::assertSame(
            [
                'window' => 'week',
                'entryCount' => 1_200,
                'storyCount' => 14,
                'durationMs' => 25_000,
                'peakMemoryBytes' => 64_000_000,
            ],
            $report->toLogContext(),
        );
    }
}
