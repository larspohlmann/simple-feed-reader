<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Model;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\StoryAssignmentModel;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Model\StoryMemberModel;
use App\Service\Curation\Trending\Model\StoryMergeModel;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use App\Service\Curation\Trending\Model\TrendingStoryModel;
use PHPUnit\Framework\TestCase;

final class TrendingBuildReportModelTest extends TestCase
{
    public function testTheLogContextNamesEveryFigureOfTheBuild(): void
    {
        $report = new TrendingBuildReportModel(
            42,
            TrendingWindow::Week,
            new \DateTimeImmutable('2031-05-14 12:00:00'),
            1_200,
            80,
            14,
            25_000,
            64_000_000,
            new StoryMatchModel(
                [new StoryAssignmentModel(self::group(), 7, [1]), new StoryAssignmentModel(self::group(), null, [])],
                [new StoryMergeModel(7, 9)],
                3,
            ),
            4,
        );

        self::assertSame(
            [
                'userId' => 42,
                'window' => 'week',
                'entryCount' => 1_200,
                'activeFeedCount' => 80,
                'storyCount' => 14,
                'durationMs' => 25_000,
                'peakMemoryBytes' => 64_000_000,
                'kept' => 1,
                'created' => 1,
                'merged' => 1,
                'split' => 3,
                'pruned' => 4,
            ],
            $report->toLogContext(),
        );
    }

    private static function group(): TrendingStoryModel
    {
        $moment = new \DateTimeImmutable('2031-05-14 08:00:00');

        return new TrendingStoryModel([new StoryMemberModel(1, 1, null)], $moment, $moment);
    }
}
