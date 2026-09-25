<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\Exception\UnpersistedEntityException;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Service\Recommendation\Exception\RecommendationRunCancelledException;
use App\Service\Recommendation\RecommendationTickCheckpoint;
use App\Tests\DbTestCase;

final class RecommendationTickCheckpointTest extends DbTestCase
{
    public function testAPersistedCancelledRunStopsTheTick(): void
    {
        $run = $this->cancelledRun();

        $this->expectException(RecommendationRunCancelledException::class);

        $this->checkpoint()->guard($run);
    }

    public function testAPersistedActiveRunDoesNotStopTheTick(): void
    {
        $user = new User('checkpoint-active@example.test', new \DateTimeImmutable('2026-08-16T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();

        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-16T09:00:00Z'));
        $run->snapshot([[1]]);
        $this->em->persist($run);
        $this->em->flush();

        $this->checkpoint()->guard($run);

        $this->addToAssertionCount(1); // guard() returned instead of throwing
    }

    public function testATransientRunIsRefused(): void
    {
        $user = new User('checkpoint-transient@example.test', new \DateTimeImmutable('2026-08-16T00:00:00Z'));
        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-16T09:00:00Z'));

        $this->expectException(UnpersistedEntityException::class);
        $this->expectExceptionMessage(RecommendationRun::class);

        $this->checkpoint()->guard($run);
    }

    private function cancelledRun(): RecommendationRun
    {
        $user = new User('checkpoint-cancelled@example.test', new \DateTimeImmutable('2026-08-16T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();

        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-16T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->cancel(new \DateTimeImmutable('2026-08-16T09:05:00Z'));
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function checkpoint(): RecommendationTickCheckpoint
    {
        /** @var RecommendationTickCheckpoint $checkpoint */
        $checkpoint = self::getContainer()->get(RecommendationTickCheckpoint::class);

        return $checkpoint;
    }
}
