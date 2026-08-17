<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Tests\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RecommendationRunRepositoryTest extends DbTestCase
{
    public function testHasActiveRunIsFalseOnAnEmptyTable(): void
    {
        self::assertFalse($this->runs()->hasActiveRun());
    }

    #[DataProvider('activeStatuses')]
    public function testHasActiveRunIsTrueWhileARunIsActive(string $status): void
    {
        $user = $this->persistUser('active@example.com');
        $this->persistRun($user, $status);

        self::assertTrue($this->runs()->hasActiveRun());
    }

    #[DataProvider('terminalStatuses')]
    public function testHasActiveRunIsFalseOnceTheOnlyRunHasEnded(string $status): void
    {
        $user = $this->persistUser('terminal@example.com');
        $this->persistRun($user, $status);

        self::assertFalse($this->runs()->hasActiveRun());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function activeStatuses(): iterable
    {
        yield 'pending' => [RecommendationRun::STATUS_PENDING];
        yield 'running' => [RecommendationRun::STATUS_RUNNING];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function terminalStatuses(): iterable
    {
        yield 'completed' => [RecommendationRun::STATUS_COMPLETED];
        yield 'cancelled' => [RecommendationRun::STATUS_CANCELLED];
        yield 'failed' => [RecommendationRun::STATUS_FAILED];
    }

    public function testFindActiveForUserReturnsTheRunningRun(): void
    {
        $userA = $this->persistUser('a@example.com');
        $userB = $this->persistUser('b@example.com');

        $this->persistRun($userA, RecommendationRun::STATUS_COMPLETED);
        $runningRun = $this->persistRun($userA, RecommendationRun::STATUS_RUNNING);
        $this->persistRun($userB, RecommendationRun::STATUS_FAILED);

        self::assertSame($runningRun->getId(), $this->runs()->findActiveForUser($userA)?->getId());
        self::assertNull($this->runs()->findActiveForUser($userB));
    }

    public function testFindActiveForUserAlsoReturnsAPendingRun(): void
    {
        $userA = $this->persistUser('a@example.com');
        $pendingRun = $this->persistRun($userA, RecommendationRun::STATUS_PENDING);

        self::assertSame($pendingRun->getId(), $this->runs()->findActiveForUser($userA)?->getId());
    }

    public function testFindLatestForUserReturnsTheNewestRunByInsertOrder(): void
    {
        $userB = $this->persistUser('b@example.com');
        $this->persistRun($userB, RecommendationRun::STATUS_COMPLETED);
        $failedRun = $this->persistRun($userB, RecommendationRun::STATUS_FAILED);

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
        $user = $this->persistUser('many@example.com');
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $this->persistRun($user, RecommendationRun::STATUS_RUNNING)->getId();
        }

        $swept = array_map(
            static fn (RecommendationRun $run): ?int => $run->getId(),
            $this->runs()->findAllActive(),
        );

        self::assertLessThan(12, \count($swept));
        self::assertSame(\array_slice($ids, 0, \count($swept)), $swept);
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $repository */
        $repository = $this->em->getRepository(RecommendationRun::class);

        return $repository;
    }

    private function persistUser(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function persistRun(User $user, string $status): RecommendationRun
    {
        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));

        if ($status !== RecommendationRun::STATUS_PENDING) {
            $run->snapshot([[1]]);
        }

        if ($status === RecommendationRun::STATUS_COMPLETED) {
            $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        }

        if ($status === RecommendationRun::STATUS_FAILED) {
            $run->fail('boom', new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        }

        if ($status === RecommendationRun::STATUS_CANCELLED) {
            $run->cancel(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        }

        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }
}
