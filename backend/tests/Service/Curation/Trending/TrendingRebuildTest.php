<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Entity\TrendingSnapshot;
use App\Enum\TrendingWindow;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Curation\Trending\Exception\TrendingBuildBusyException;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use App\Service\Curation\Trending\Model\TrendingRebuildReportModel;
use App\Service\Curation\Trending\SnapshotBuilder\SnapshotBuilderInterface;
use App\Service\Curation\Trending\SnapshotBuilder\TrendingSnapshotBuilder;
use App\Service\Curation\Trending\TrendingRebuild;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\TrendingFixtures;
use App\Tests\Support\TrendingWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class TrendingRebuildTest extends DbTestCase
{
    public function testBuildsEveryWindowNeverBuilt(): void
    {
        self::assertSame([TrendingWindow::Day, TrendingWindow::Week], self::windowsOf($this->rebuildDue()));
    }

    public function testABuildRunningElsewhereLeavesEveryWindowDueAndThrowsNothing(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $running = $locks->createLock(TrendingSnapshotBuilder::LOCK_NAME);
        $running->acquire();
        $clock = new MockClock('2031-05-14 12:00:00', 'UTC');
        $rebuild = TrendingWiring::rebuild($this->entityManager, $clock, $locks);

        self::assertSame([], self::windowsOf($rebuild->rebuildDue()));
        self::assertSame([], self::windowsOf($rebuild->rebuildDueBefore($clock->now()->modify('+1 hour'))));

        $running->release();

        self::assertSame([TrendingWindow::Day, TrendingWindow::Week], self::windowsOf($rebuild->rebuildDue()));
    }

    public function testAWindowFoundBusyIsSkippedAndTheNextOneStillBuilds(): void
    {
        $builder = new class implements SnapshotBuilderInterface {
            public function build(TrendingWindow $window): TrendingBuildReportModel
            {
                if ($window === TrendingWindow::Day) {
                    throw new TrendingBuildBusyException();
                }

                return new TrendingBuildReportModel(
                    $window,
                    new \DateTimeImmutable('2031-05-14 12:00:00'),
                    0,
                    0,
                    0,
                    0,
                    new StoryMatchModel([], [], 0),
                    0,
                );
            }
        };
        $clock = new NaiveUtcClock(new MockClock('2031-05-14 12:00:00', 'UTC'));
        $logger = new RecordingLogger();
        $rebuild = new TrendingRebuild($builder, TrendingWiring::snapshots($this->entityManager), $clock, $logger);

        self::assertSame([TrendingWindow::Week], self::windowsOf($rebuild->rebuildDue()));
        self::assertSame(
            [
                [
                    'level' => LogLevel::DEBUG,
                    'message' => 'Trending build skipped: another build is running.',
                    'context' => ['window' => 'day'],
                ],
            ],
            $logger->records,
        );
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

    public function testAtTheDeadlineNotEvenABuildThatTookNoTimeStarts(): void
    {
        $this->snapshotBuiltAt(TrendingWindow::Day, '2031-05-14 11:00:00');
        $this->snapshotBuiltAt(TrendingWindow::Week, '2031-05-14 10:00:00');
        $clock = new MockClock('2031-05-14 12:00:00', 'UTC');

        $report = $this->rebuildTaking($clock, 0)->rebuildDueBefore($clock->now());

        self::assertSame([], self::windowsOf($report));
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

                return new TrendingBuildReportModel(
                    $window,
                    $this->naiveClock->now(),
                    0,
                    0,
                    0,
                    0,
                    new StoryMatchModel([], [], 0),
                    0,
                );
            }
        };

        return new TrendingRebuild(
            $builder,
            TrendingWiring::snapshots($this->entityManager),
            $naiveClock,
            new NullLogger(),
        );
    }

    private function snapshotBuiltAt(TrendingWindow $window, string $builtAt, int $durationMilliseconds = 0): void
    {
        $this->entityManager->persist(
            new TrendingSnapshot(
                (new TrendingFixtures($this->entityManager))->owner(),
                $window,
                new \DateTimeImmutable($builtAt),
                0,
                0,
                0,
                $durationMilliseconds,
                0,
            ),
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
