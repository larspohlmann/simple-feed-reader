<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Tests\DbTestCase;

final class RecommendationRunRepositoryTest extends DbTestCase
{
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

        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }
}
