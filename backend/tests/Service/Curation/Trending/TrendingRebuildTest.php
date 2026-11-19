<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Entity\TrendingSnapshot;
use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Repository\TrendingEntryRepository;
use App\Repository\UserRepository;
use App\Service\Curation\Trending\Exception\TrendingBuildBusyException;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use App\Service\Curation\Trending\Model\TrendingRebuildReportModel;
use App\Service\Curation\Trending\SnapshotBuilder\SnapshotBuilderInterface;
use App\Service\Curation\Trending\SnapshotBuilder\TrendingSnapshotBuilder;
use App\Service\Curation\Trending\TrendingRebuild;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\TrendingFixtures;
use App\Tests\Support\TrendingWiring;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
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
        $running = $locks->createLock(TrendingSnapshotBuilder::LOCK_NAME . '-' . $this->activeOwner()->requireId());
        $running->acquire();
        $clock = new MockClock('2031-05-14 12:00:00', 'UTC');
        $rebuild = TrendingWiring::rebuild($this->entityManager, $this->registry(), $clock, $locks);

        self::assertSame([], self::windowsOf($rebuild->rebuildDue()));
        self::assertSame([], self::windowsOf($rebuild->rebuildDueBefore($clock->now()->modify('+1 hour'))));

        $running->release();

        self::assertSame([TrendingWindow::Day, TrendingWindow::Week], self::windowsOf($rebuild->rebuildDue()));
    }

    public function testAWindowFoundBusyIsSkippedAndTheNextOneStillBuilds(): void
    {
        $builder = new class implements SnapshotBuilderInterface {
            public function build(int $userId, TrendingWindow $window): TrendingBuildReportModel
            {
                if ($window === TrendingWindow::Day) {
                    throw new TrendingBuildBusyException();
                }

                return new TrendingBuildReportModel(
                    $userId,
                    $window,
                    new \DateTimeImmutable('2031-05-14 12:00:00'),
                    0,
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
        $ownerId = $this->activeOwner()->requireId();
        $rebuild = new TrendingRebuild(
            $builder,
            TrendingWiring::snapshots($this->entityManager),
            new TrendingEntryRepository($this->entityManager),
            $this->users(),
            $clock,
            $logger,
            $this->registry(),
        );

        self::assertSame([TrendingWindow::Week], self::windowsOf($rebuild->rebuildDue()));
        self::assertSame(
            [
                [
                    'level' => LogLevel::DEBUG,
                    'message' => 'Trending build skipped: another build is running.',
                    'context' => ['user' => $ownerId, 'window' => 'day'],
                ],
            ],
            $logger->records,
        );
    }

    public function testRebuildsAWindowOnceItsSnapshotIsAsOldAsItsInterval(): void
    {
        $this->snapshotBuiltAt(TrendingWindow::Day, '2031-05-14 11:50:00');
        $this->snapshotBuiltAt(TrendingWindow::Week, '2031-05-14 11:00:00');

        self::assertSame([TrendingWindow::Week, TrendingWindow::Day], self::windowsOf($this->rebuildDue()));
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

        $report = TrendingWiring::rebuild($this->entityManager, $this->registry(), $clock)
            ->rebuildDueBefore($clock->now()->modify('+1 second'));

        self::assertSame([TrendingWindow::Week], self::windowsOf($report));
    }

    public function testBeforeADeadlineTheNextWindowWaitsWhenTheBuildBeforeItUsedUpTheTime(): void
    {
        $this->snapshotBuiltAt(TrendingWindow::Day, '2031-05-14 11:00:00', 2_000);
        $this->snapshotBuiltAt(TrendingWindow::Week, '2031-05-14 10:00:00', 2_000);
        $clock = new MockClock('2031-05-14 12:00:00', 'UTC');

        $report = $this->rebuildTaking($clock, 3)->rebuildDueBefore($clock->now()->modify('+4 seconds'));

        self::assertSame([TrendingWindow::Week], self::windowsOf($report));
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

    public function testBuildsEachActiveUsersDueWindows(): void
    {
        $first = $this->activeOwner();
        $second = (new TrendingFixtures($this->entityManager))->reader('second-reader@example.com');
        $second->setLastLoginAt(new \DateTimeImmutable('2031-05-14 10:00:00'));
        $this->entityManager->flush();
        $builder = new RecordingSnapshotBuilder();

        $this->rebuildWith($builder)->rebuildDue();

        self::assertSame(
            [
                [$first->requireId(), TrendingWindow::Day],
                [$first->requireId(), TrendingWindow::Week],
                [$second->requireId(), TrendingWindow::Day],
                [$second->requireId(), TrendingWindow::Week],
            ],
            $builder->calls,
        );
    }

    public function testSkipsAPairWithNothingNew(): void
    {
        $this->activeOwner();
        $this->snapshotBuiltAt(TrendingWindow::Day, '2031-05-14 11:40:00');
        $this->snapshotBuiltAt(TrendingWindow::Week, '2031-05-14 11:40:00');
        $this->entityManager->createQuery('DELETE FROM App\Entity\Entry')->execute();
        $builder = new RecordingSnapshotBuilder();

        $this->rebuildWith($builder)->rebuildDue();

        self::assertSame([], $builder->calls);
    }

    public function testOneUsersBusyBuildDoesNotStopTheNext(): void
    {
        $busy = $this->activeOwner();
        $other = (new TrendingFixtures($this->entityManager))->reader('other-reader@example.com');
        $other->setLastLoginAt(new \DateTimeImmutable('2031-05-14 10:00:00'));
        $this->entityManager->flush();
        $builder = new RecordingSnapshotBuilder($busy->requireId());

        $this->rebuildWith($builder)->rebuildDue();

        self::assertSame(
            [[$other->requireId(), TrendingWindow::Day], [$other->requireId(), TrendingWindow::Week]],
            $builder->calls,
        );
    }

    public function testALockTheDatabaseGaveUpLeavesThatUserDueAndTheNextUserStillBuilds(): void
    {
        $deadlocked = $this->activeOwner();
        $other = $this->loggedInReader('other-reader@example.com');
        $builder = new RecordingSnapshotBuilder($deadlocked->requireId(), self::deadlock());
        $logger = new RecordingLogger();

        $this->rebuildWith($builder, $logger)->rebuildDue();

        self::assertSame(
            [[$other->requireId(), TrendingWindow::Day], [$other->requireId(), TrendingWindow::Week]],
            $builder->calls,
        );
        self::assertSame(
            [
                [LogLevel::WARNING, ['user' => $deadlocked->requireId(), 'window' => 'day']],
                [LogLevel::WARNING, ['user' => $deadlocked->requireId(), 'window' => 'week']],
            ],
            array_map(
                static fn (array $record): array => [
                    $record['level'],
                    array_diff_key($record['context'], ['exception' => true]),
                ],
                $logger->records,
            ),
        );
    }

    public function testTheNextBuildAfterALockTheDatabaseGaveUpFindsTheEntityManagerOpen(): void
    {
        $deadlocked = $this->activeOwner();
        $this->loggedInReader('other-reader@example.com');
        $builder = new class ($this->registry(), $deadlocked->requireId()) implements SnapshotBuilderInterface {
            /** @var list<bool> */
            public array $managerOpenAtEachBuild = [];

            public function __construct(private readonly ManagerRegistry $registry, private readonly int $deadlockedId)
            {
            }

            public function build(int $userId, TrendingWindow $window): TrendingBuildReportModel
            {
                $entityManager = $this->registry->getManager();
                \assert($entityManager instanceof EntityManagerInterface);
                $this->managerOpenAtEachBuild[] = $entityManager->isOpen();
                if ($userId === $this->deadlockedId) {
                    $entityManager->close();

                    throw TrendingRebuildTest::deadlock();
                }

                return new TrendingBuildReportModel(
                    $userId,
                    $window,
                    new \DateTimeImmutable('2031-05-14 12:00:00'),
                    0,
                    0,
                    0,
                    0,
                    0,
                    new StoryMatchModel([], [], 0),
                    0,
                );
            }
        };

        $this->rebuildWith($builder)->rebuildDue();

        self::assertSame([true, true, true, true], $builder->managerOpenAtEachBuild);
    }

    public static function deadlock(): DeadlockException
    {
        return new DeadlockException(new class ('Deadlock found when trying to get lock') extends AbstractException {
        }, null);
    }

    public function testLeavesAUserWhoLastLoggedInOverTwoWeeksAgoAlone(): void
    {
        $owner = $this->activeOwner();
        $owner->setLastLoginAt(new \DateTimeImmutable('2031-04-30 11:59:59'));
        $this->entityManager->flush();
        $builder = new RecordingSnapshotBuilder();

        $this->rebuildWith($builder)->rebuildDue();

        self::assertSame([], $builder->calls);
    }

    public function testTheStalestPairAcrossUsersBuildsFirstWhenTimeFitsOneBuild(): void
    {
        $first = $this->activeOwner();
        $second = $this->loggedInReader('stalest-reader@example.com');
        $this->newsFor($first, '2031-05-14 11:59:00');
        $this->newsFor($second, '2031-05-14 11:59:00');
        $this->persistSnapshot($first, TrendingWindow::Day, '2031-05-14 11:00:00', 2_000);
        $this->persistSnapshot($second, TrendingWindow::Day, '2031-05-14 10:00:00', 2_000);
        $this->persistSnapshot($first, TrendingWindow::Week, '2031-05-14 11:59:00', 0);
        $this->persistSnapshot($second, TrendingWindow::Week, '2031-05-14 11:59:00', 0);
        $clock = new MockClock('2031-05-14 12:00:00', 'UTC');

        $report = $this->rebuildTaking($clock, 3)->rebuildDueBefore($clock->now()->modify('+4 seconds'));

        self::assertSame([$second->requireId()], self::usersOf($report));
    }

    public function testBeforeADeadlineEachUsersOwnLastBuildDurationIsItsEstimate(): void
    {
        $slow = $this->activeOwner();
        $quick = $this->loggedInReader('quick-reader@example.com');
        foreach ([$slow, $quick] as $user) {
            $this->newsFor($user, '2031-05-14 11:59:00');
            $this->persistSnapshot($user, TrendingWindow::Week, '2031-05-14 11:59:00', 0);
        }
        $this->persistSnapshot($slow, TrendingWindow::Day, '2031-05-14 11:00:00', 2_000);
        $this->persistSnapshot($quick, TrendingWindow::Day, '2031-05-14 11:00:00', 500);
        $clock = new MockClock('2031-05-14 12:00:00', 'UTC');

        $report = $this->rebuildTaking($clock, 0)->rebuildDueBefore($clock->now()->modify('+1 second'));

        self::assertSame([$quick->requireId()], self::usersOf($report));
    }

    public function testAnEntryCreatedExactlyWhenTheSnapshotWasBuiltIsNotNews(): void
    {
        $owner = $this->activeOwner();
        $this->newsFor($owner, '2031-05-14 11:00:00');
        $this->persistSnapshot($owner, TrendingWindow::Day, '2031-05-14 11:00:00', 0);
        $this->persistSnapshot($owner, TrendingWindow::Week, '2031-05-14 11:00:00', 0);
        $builder = new RecordingSnapshotBuilder();

        $this->rebuildWith($builder)->rebuildDue();

        self::assertSame([], $builder->calls);
    }

    public function testAUserWhoLoggedInExactlyTwoWeeksAgoIsStillActive(): void
    {
        $owner = $this->activeOwner();
        $owner->setLastLoginAt(new \DateTimeImmutable('2031-04-30 12:00:00'));
        $this->entityManager->flush();
        $builder = new RecordingSnapshotBuilder();

        $this->rebuildWith($builder)->rebuildDue();

        self::assertSame(
            [[$owner->requireId(), TrendingWindow::Day], [$owner->requireId(), TrendingWindow::Week]],
            $builder->calls,
        );
    }

    private function loggedInReader(string $email): User
    {
        $reader = (new TrendingFixtures($this->entityManager))->reader($email);
        $reader->setLastLoginAt(new \DateTimeImmutable('2031-05-14 10:00:00'));
        $this->entityManager->flush();

        return $reader;
    }

    private function newsFor(User $user, string $createdAt): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $feed = $fixtures->feedNobodyFollows(uniqid('news', true));
        $fixtures->subscribe($user, $feed);
        $fixtures->entry($feed, 'Headline', $createdAt);
        $this->entityManager->flush();
    }

    /** @return list<int> */
    private static function usersOf(TrendingRebuildReportModel $report): array
    {
        return array_map(
            static fn (TrendingBuildReportModel $build): int => $build->userId,
            $report->builds,
        );
    }

    private function rebuildWith(
        SnapshotBuilderInterface $builder,
        LoggerInterface $logger = new NullLogger(),
    ): TrendingRebuild {
        return new TrendingRebuild(
            $builder,
            TrendingWiring::snapshots($this->entityManager),
            new TrendingEntryRepository($this->entityManager),
            $this->users(),
            new NaiveUtcClock(new MockClock('2031-05-14 12:00:00', 'UTC')),
            $logger,
            $this->registry(),
        );
    }

    private function rebuildDue(): TrendingRebuildReportModel
    {
        $clock = new MockClock('2031-05-14 12:00:00', 'UTC');
        $this->activeOwner();

        return TrendingWiring::rebuild($this->entityManager, $this->registry(), $clock)->rebuildDue();
    }

    private function rebuildTaking(MockClock $clock, int $secondsPerBuild): TrendingRebuild
    {
        $this->activeOwner();
        $naiveClock = new NaiveUtcClock($clock);
        $builder = new class ($clock, $naiveClock, $secondsPerBuild) implements SnapshotBuilderInterface {
            public function __construct(
                private readonly MockClock $clock,
                private readonly NaiveUtcClock $naiveClock,
                private readonly int $secondsPerBuild,
            ) {
            }

            public function build(int $userId, TrendingWindow $window): TrendingBuildReportModel
            {
                $this->clock->sleep($this->secondsPerBuild);

                return new TrendingBuildReportModel(
                    $userId,
                    $window,
                    $this->naiveClock->now(),
                    0,
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
            new TrendingEntryRepository($this->entityManager),
            $this->users(),
            $naiveClock,
            new NullLogger(),
            $this->registry(),
        );
    }

    private function snapshotBuiltAt(TrendingWindow $window, string $builtAt, int $durationMilliseconds = 0): void
    {
        $this->newsAt('2031-05-14 11:59:00');
        $owner = (new TrendingFixtures($this->entityManager))->owner();
        $this->persistSnapshot($owner, $window, $builtAt, $durationMilliseconds);
    }

    private function persistSnapshot(
        User $user,
        TrendingWindow $window,
        string $builtAt,
        int $durationMilliseconds,
    ): void {
        $this->entityManager->persist(
            new TrendingSnapshot(
                $user,
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

    private function activeOwner(): User
    {
        $owner = (new TrendingFixtures($this->entityManager))->owner();
        $owner->setLastLoginAt(new \DateTimeImmutable('2031-05-14 11:00:00'));
        $this->entityManager->flush();

        return $owner;
    }

    private function newsAt(string $createdAt): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $this->activeOwner();
        $fixtures->entry($fixtures->feed(uniqid('news', true)), 'Headline', $createdAt);
        $this->entityManager->flush();
    }

    private function users(): UserRepository
    {
        /** @var UserRepository $users */
        $users = $this->entityManager->getRepository(User::class);

        return $users;
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
