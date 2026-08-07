<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\WorkerHeartbeat;
use App\Repository\WorkerHeartbeatRepository;
use App\Service\Worker\WorkerPresence;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

final class WorkerPresenceTest extends DbTestCase
{
    public function testContainerWiringMarksAndReportsAlive(): void
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        $presence->markRecommendationSweep();

        self::assertTrue($presence->isRecommendationWorkerAlive());
    }

    public function testNoHeartbeatMeansNoWorker(): void
    {
        self::assertFalse($this->presenceAt('2026-08-07 12:00:00')->isRecommendationWorkerAlive());
    }

    public function testAFreshHeartbeatMeansAlive(): void
    {
        $this->repository()->touch(WorkerPresence::RECOMMENDATION_SWEEP, new \DateTimeImmutable('2026-08-07 11:59:40'));

        self::assertTrue($this->presenceAt('2026-08-07 12:00:00')->isRecommendationWorkerAlive());
    }

    public function testExactlyThirtySecondsOldIsStillAlive(): void
    {
        $this->repository()->touch(WorkerPresence::RECOMMENDATION_SWEEP, new \DateTimeImmutable('2026-08-07 11:59:30'));

        self::assertTrue($this->presenceAt('2026-08-07 12:00:00')->isRecommendationWorkerAlive());
    }

    public function testThirtyOneSecondsOldIsDead(): void
    {
        $this->repository()->touch(WorkerPresence::RECOMMENDATION_SWEEP, new \DateTimeImmutable('2026-08-07 11:59:29'));

        self::assertFalse($this->presenceAt('2026-08-07 12:00:00')->isRecommendationWorkerAlive());
    }

    public function testTouchTwiceUpdatesTheOneRow(): void
    {
        $this->repository()->touch('x', new \DateTimeImmutable('2026-08-07 11:00:00'));
        $this->repository()->touch('x', new \DateTimeImmutable('2026-08-07 11:00:10'));

        self::assertEquals(new \DateTimeImmutable('2026-08-07 11:00:10'), $this->repository()->findTouchedAt('x'));
    }

    private function presenceAt(string $now): WorkerPresence
    {
        return new WorkerPresence($this->repository(), new MockClock($now));
    }

    private function repository(): WorkerHeartbeatRepository
    {
        /** @var WorkerHeartbeatRepository $repository */
        $repository = $this->em->getRepository(WorkerHeartbeat::class);

        return $repository;
    }
}
