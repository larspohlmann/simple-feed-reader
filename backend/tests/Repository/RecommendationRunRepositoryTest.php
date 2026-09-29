<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RunStatus;
use App\Repository\RecommendationRunRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;
use PHPUnit\Framework\Attributes\DataProvider;

final class RecommendationRunRepositoryTest extends DbTestCase
{
    use SeedsUsers;

    public function testHasActiveRunIsFalseOnAnEmptyTable(): void
    {
        self::assertFalse($this->runs()->hasActiveRun());
    }

    #[DataProvider('activeStatuses')]
    public function testHasActiveRunIsTrueWhileARunIsActive(RunStatus $status): void
    {
        $user = $this->user('active@example.com');
        $this->persistRun($user, $status);

        self::assertTrue($this->runs()->hasActiveRun());
    }

    #[DataProvider('terminalStatuses')]
    public function testHasActiveRunIsFalseOnceTheOnlyRunHasEnded(RunStatus $status): void
    {
        $user = $this->user('terminal@example.com');
        $this->persistRun($user, $status);

        self::assertFalse($this->runs()->hasActiveRun());
    }

    /**
     * @return iterable<string, array{RunStatus}>
     */
    public static function activeStatuses(): iterable
    {
        yield 'pending' => [RunStatus::Pending];
        yield 'running' => [RunStatus::Running];
    }

    /**
     * @return iterable<string, array{RunStatus}>
     */
    public static function terminalStatuses(): iterable
    {
        yield 'completed' => [RunStatus::Completed];
        yield 'cancelled' => [RunStatus::Cancelled];
        yield 'failed' => [RunStatus::Failed];
    }

    public function testFindActiveForUserReturnsTheRunningRun(): void
    {
        $userA = $this->user('a@example.com');
        $userB = $this->user('b@example.com');

        $this->persistRun($userA, RunStatus::Completed);
        $runningRun = $this->persistRun($userA, RunStatus::Running);
        $this->persistRun($userB, RunStatus::Failed);

        self::assertSame($runningRun->getId(), $this->runs()->findActiveForUser($userA)?->getId());
        self::assertNull($this->runs()->findActiveForUser($userB));
    }

    public function testFindActiveForUserAlsoReturnsAPendingRun(): void
    {
        $userA = $this->user('a@example.com');
        $pendingRun = $this->persistRun($userA, RunStatus::Pending);

        self::assertSame($pendingRun->getId(), $this->runs()->findActiveForUser($userA)?->getId());
    }

    public function testFindLatestForUserReturnsTheNewestRunByInsertOrder(): void
    {
        $userB = $this->user('b@example.com');
        $this->persistRun($userB, RunStatus::Completed);
        $failedRun = $this->persistRun($userB, RunStatus::Failed);

        self::assertSame($failedRun->getId(), $this->runs()->findLatestForUser($userB)?->getId());
    }

    /**
     * A firing's duration is the SUM over the runs it ticks and one run can
     * spend a whole provider timeout, so an unbounded result turns a
     * "ten-second" sweep into an hour-long one as accounts add up. Oldest
     * first keeps the cap fair: the runs at the head of the queue are the
     * ones that finish and leave, and every later run reaches the window in
     * turn (#311 final review).
     */
    public function testTheSweepSetIsBoundedAndTakesTheOldestRunsFirst(): void
    {
        $user = $this->user('many@example.com');
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $this->persistRun($user, RunStatus::Running)->getId();
        }

        $swept = array_map(
            static fn (RecommendationRun $run): ?int => $run->getId(),
            $this->runs()->findAllActive(),
        );

        self::assertLessThan(12, \count($swept));
        self::assertSame(\array_slice($ids, 0, \count($swept)), $swept);
    }

    public function testWhatARunningRunRecordsThroughItsThrottleAndCallAttemptsIsPersisted(): void
    {
        $run = $this->persistRun($this->user('views@example.com'), RunStatus::Running);
        $run->getRunningThrottle()->deferUntil(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $run->getRunningThrottle()->reduceConcurrency(8);
        $run->getRunningCallAttempts()->recordInvalidReply('garbage');
        $run->getRunningCallAttempts()->recordTransportFailure();
        $this->em->flush();
        $this->em->clear();

        $persisted = $this->em->find(RecommendationRun::class, $run->requireId());

        self::assertNotNull($persisted);
        self::assertSame('2026-08-07 09:05:00', $persisted->getRetryNotBefore()?->format('Y-m-d H:i:s'));
        self::assertSame(4, $persisted->getWaveConcurrencyCap(8));
        self::assertSame(1, $persisted->getAttempts());
        self::assertSame('garbage', $persisted->getLastInvalidReply());
        self::assertSame(1, $persisted->getTransportFailures());
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $repository */
        $repository = $this->em->getRepository(RecommendationRun::class);

        return $repository;
    }

    private function persistRun(User $user, RunStatus $status): RecommendationRun
    {
        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));

        if ($status !== RunStatus::Pending) {
            $run->snapshot([[1]]);
        }

        if ($status === RunStatus::Completed) {
            $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        }

        if ($status === RunStatus::Failed) {
            $run->fail('boom', new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        }

        if ($status === RunStatus::Cancelled) {
            $run->cancel(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        }

        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }
}
