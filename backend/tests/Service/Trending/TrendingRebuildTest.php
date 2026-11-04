<?php

declare(strict_types=1);

namespace App\Tests\Service\Trending;

use App\Entity\TrendingSnapshot;
use App\Enum\TrendingWindow;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Trending\Model\TrendingBuildReportModel;
use App\Service\Trending\Model\TrendingRebuildReportModel;
use App\Service\Trending\SnapshotBuilder\SnapshotBuilderInterface;
use App\Service\Trending\TrendingRebuild;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingWiring;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testBeforeADeadlineAWindowStartsOnlyIfItsLastBuildFitsInTheTimeLeft(): void
    {
        $this->snapshotBuiltAt(TrendingWindow::Day, '2031-05-14 11:00:00', 2_000);
        $this->snapshotBuiltAt(TrendingWindow::Week, '2031-05-14 10:00:00', 1_000);
        $clock = new MockClock('2031-05-14 12:00:00', 'UTC');

        $report = TrendingWiring::rebuild($this->entityManager, $clock)
            ->rebuildDueBefore($clock->now()->modify('+1 second'));

        self::assertSame([TrendingWindow::Week], self::windowsOf($report));
    }

    public function testBeforeADeadlineTheNextWindowWaitsWhenTheBuildBeforeItUsedUpTheTime(): void
    {
        $this->snapshotBuiltAt(TrendingWindow::Day, '2031-05-14 11:00:00', 2_000);
        $this->snapshotBuiltAt(TrendingWindow::Week, '2031-05-14 10:00:00', 2_000);
        $clock = new MockClock('2031-05-14 12:00:00', 'UTC');

        $report = $this->rebuildTaking($clock, 3)->rebuildDueBefore($clock->now()->modify('+4 seconds'));

        self::assertSame([TrendingWindow::Day], self::windowsOf($report));
    }

    /** @param list<TrendingWindow> $expectedWindows */
    #[DataProvider('firstBuildDeadlines')]
    public function testBeforeADeadlineAWindowNeverBuiltStartsOnlyIfItsEstimateFits(
        string $timeLeft,
        array $expectedWindows,
    ): void {
        $clock = new MockClock('2031-05-14 12:00:00', 'UTC');

        $report = $this->rebuildTaking($clock, 0)->rebuildDueBefore($clock->now()->modify($timeLeft));

        self::assertSame($expectedWindows, self::windowsOf($report));
    }

    /** @return iterable<string, array{string, list<TrendingWindow>}> */
    public static function firstBuildDeadlines(): iterable
    {
        yield 'short of the day estimate' => ['+4999 milliseconds', []];
        yield 'the day estimate' => ['+5 seconds', [TrendingWindow::Day]];
        yield 'short of the week estimate' => ['+29999 milliseconds', [TrendingWindow::Day]];
        yield 'the week estimate' => ['+30 seconds', [TrendingWindow::Day, TrendingWindow::Week]];
    }

    private function rebuildDue(): TrendingRebuildReportModel
    {
        $clock = new MockClock('2031-05-14 12:00:00', 'UTC');

        return TrendingWiring::rebuild($this->entityManager, $clock)->rebuildDue();
    }

    private function rebuildTaking(MockClock $clock, int $secondsPerBuild): TrendingRebuild
    {
        $naiveClock = new NaiveUtcClock($clock);
        $builder = new class ($clock, $naiveClock, $secondsPerBuild) implements SnapshotBuilderInterface {
            public function __construct(
                private readonly MockClock $clock,
                private readonly NaiveUtcClock $naiveClock,
                private readonly int $secondsPerBuild,
            ) {
            }

            public function build(TrendingWindow $window): TrendingBuildReportModel
            {
                $this->clock->sleep($this->secondsPerBuild);

                return new TrendingBuildReportModel($window, $this->naiveClock->now(), 0, 0, 0, 0);
            }
        };

        return new TrendingRebuild($builder, TrendingWiring::snapshots($this->entityManager), $naiveClock);
    }

    private function snapshotBuiltAt(TrendingWindow $window, string $builtAt, int $durationMilliseconds = 0): void
    {
        $this->entityManager->persist(
            new TrendingSnapshot($window, new \DateTimeImmutable($builtAt), 0, 0, $durationMilliseconds, 0),
        );
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
