<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\TrendingRebuildCommand;
use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Exception\TrendingBuildBusyException;
use App\Service\Curation\Trending\Model\StoryAssignmentModel;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Model\StoryMemberModel;
use App\Service\Curation\Trending\Model\StoryMergeModel;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use App\Service\Curation\Trending\Model\TrendingStoryModel;
use App\Service\Curation\Trending\SnapshotBuilder\SnapshotBuilderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TrendingRebuildCommandTest extends TestCase
{
    /** @return iterable<string, array{string, TrendingWindow}> */
    public static function windows(): iterable
    {
        yield 'day' => ['day', TrendingWindow::Day];
        yield 'week' => ['week', TrendingWindow::Week];
    }

    #[DataProvider('windows')]
    public function testItBuildsTheNamedWindowAndPrintsItsCounts(string $argument, TrendingWindow $window): void
    {
        $builder = $this->createMock(SnapshotBuilderInterface::class);
        $builder->expects($this->once())->method('build')->with(7, $window)->willReturn(self::reportOf($window));
        $tester = new CommandTester(new TrendingRebuildCommand($builder));

        $tester->execute(['window' => $argument, '--user' => '7']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/\buserId\s+7\b/', $display);
        self::assertMatchesRegularExpression('/\bactiveFeedCount\s+80\b/', $display);
        self::assertMatchesRegularExpression('/\bwindow\s+' . $window->value . '\b/', $display);
        self::assertMatchesRegularExpression('/\bentryCount\s+1200\b/', $display);
        self::assertMatchesRegularExpression('/\bstoryCount\s+14\b/', $display);
        self::assertMatchesRegularExpression('/\bkept\s+2\b/', $display);
        self::assertMatchesRegularExpression('/\bcreated\s+1\b/', $display);
        self::assertMatchesRegularExpression('/\bmerged\s+1\b/', $display);
        self::assertMatchesRegularExpression('/\bsplit\s+3\b/', $display);
        self::assertMatchesRegularExpression('/\bpruned\s+4\b/', $display);
    }

    public function testItSaysItSkippedWhileAnotherBuildRuns(): void
    {
        $builder = $this->createStub(SnapshotBuilderInterface::class);
        $builder->method('build')->willThrowException(new TrendingBuildBusyException());
        $tester = new CommandTester(new TrendingRebuildCommand($builder));

        $tester->execute(['window' => 'day', '--user' => '7']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Skipped: another Trending build is running.', $tester->getDisplay());
    }

    public function testItNeedsAUser(): void
    {
        $builder = $this->createMock(SnapshotBuilderInterface::class);
        $builder->expects($this->never())->method('build');
        $tester = new CommandTester(new TrendingRebuildCommand($builder));

        $tester->execute(['window' => 'day']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--user', $tester->getDisplay());
    }

    public function testAnUnknownWindowFails(): void
    {
        $builder = $this->createMock(SnapshotBuilderInterface::class);
        $builder->expects($this->never())->method('build');
        $tester = new CommandTester(new TrendingRebuildCommand($builder));

        $tester->execute(['window' => 'month', '--user' => '7']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Unknown window. Use one of: day, week.', $tester->getDisplay());
    }

    private static function reportOf(TrendingWindow $window): TrendingBuildReportModel
    {
        $moment = new \DateTimeImmutable('2031-05-14 08:00:00');
        $group = new TrendingStoryModel([new StoryMemberModel(1, 1, null)], $moment, $moment);

        return new TrendingBuildReportModel(
            7,
            $window,
            $moment,
            1_200,
            80,
            14,
            25_000,
            64_000_000,
            new StoryMatchModel(
                [
                    new StoryAssignmentModel($group, 7, [1]),
                    new StoryAssignmentModel($group, 8, [1]),
                    new StoryAssignmentModel($group, null, []),
                ],
                [new StoryMergeModel(7, 9)],
                3,
            ),
            4,
        );
    }
}
