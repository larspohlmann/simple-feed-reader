<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\WorkerHeartbeat;
use App\Repository\WorkerHeartbeatRepository;
use App\Service\Recommendation\OpenAiCompatibleChatClient;
use App\Service\Worker\WorkerPresence;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

final class WorkerPresenceTest extends DbTestCase
{
    public function testContainerWiringMarksAndReportsAlive(): void
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        $presence->markPersistentWorkerSweep();

        self::assertTrue($presence->hasPersistentRecommendationWorker());
    }

    public function testNoHeartbeatMeansNoWorker(): void
    {
        self::assertFalse($this->presenceAt('2026-08-07 12:00:00')->hasPersistentRecommendationWorker());
    }

    public function testAFreshHeartbeatMeansAlive(): void
    {
        $this->repository()->touch(WorkerPresence::RECOMMENDATION_SWEEP, new \DateTimeImmutable('2026-08-07 11:59:40'));

        self::assertTrue($this->presenceAt('2026-08-07 12:00:00')->hasPersistentRecommendationWorker());
    }

    public function testTheHeartbeatIsAliveExactlyUpToTheEdgeOfTheWindow(): void
    {
        $this->repository()->touch(
            WorkerPresence::RECOMMENDATION_SWEEP,
            $this->secondsBeforeNoon(WorkerPresence::FRESH_SECONDS),
        );

        self::assertTrue($this->presenceAt('2026-08-07 12:00:00')->hasPersistentRecommendationWorker());
    }

    public function testOneSecondPastTheWindowIsDead(): void
    {
        $this->repository()->touch(
            WorkerPresence::RECOMMENDATION_SWEEP,
            $this->secondsBeforeNoon(WorkerPresence::FRESH_SECONDS + 1),
        );

        self::assertFalse($this->presenceAt('2026-08-07 12:00:00')->hasPersistentRecommendationWorker());
    }

    /**
     * The invariant the whole arbitration rests on, pinned as a RELATIONSHIP
     * rather than as a number: the sweep touches the heartbeat once per run
     * it advances, and advancing one run costs at most one provider call, so
     * a window no wider than that call declares a working worker dead. It did
     * — 30 s against a 120 s ceiling — and the client then read the per-user
     * lock as a user-facing failure and stopped polling a healthy run (#311
     * final review, Critical 2). Asserting a literal 30 would have proved
     * nothing; this fails the moment either constant moves the wrong way.
     */
    public function testTheFreshnessWindowOutlastsOneProviderCall(): void
    {
        self::assertGreaterThan(
            OpenAiCompatibleChatClient::TIMEOUT_SECONDS,
            WorkerPresence::FRESH_SECONDS,
            'A worker mid-provider-call must still count as alive.',
        );
    }

    /**
     * And with room for the wait until the next firing on top: the last touch
     * of one firing is followed by the rest of that run's work and then by the
     * ten seconds until the sweep fires again.
     */
    public function testTheFreshnessWindowAlsoCoversTheGapUntilTheNextSweep(): void
    {
        $sweepIntervalSeconds = 10;

        self::assertGreaterThan(
            OpenAiCompatibleChatClient::TIMEOUT_SECONDS + $sweepIntervalSeconds,
            WorkerPresence::FRESH_SECONDS,
        );
    }

    private function secondsBeforeNoon(int $seconds): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('2026-08-07 12:00:00'))->modify(sprintf('-%d seconds', $seconds));
    }

    public function testTouchTwiceUpdatesTheOneRow(): void
    {
        $this->repository()->touch('x', new \DateTimeImmutable('2026-08-07 11:00:00'));
        $this->repository()->touch('x', new \DateTimeImmutable('2026-08-07 11:00:10'));

        self::assertEquals(new \DateTimeImmutable('2026-08-07 11:00:10'), $this->repository()->findTouchedAt('x'));
    }

    /**
     * The drainer surrenders liveness on the way out, and the exit paths that
     * do so run whether or not it ever marked anything: a command that dies
     * before its first sweep still reaches the same `finally`. Forgetting a
     * name that was never touched must therefore be a no-op, not a failure.
     */
    public function testForgettingAHeartbeatThatWasNeverTouchedDoesNothing(): void
    {
        $this->repository()->forget('never-touched');

        self::assertNull($this->repository()->findTouchedAt('never-touched'));
    }

    public function testForgettingAHeartbeatRemovesItsRow(): void
    {
        $this->repository()->touch('x', new \DateTimeImmutable('2026-08-07 11:00:00'));

        $this->repository()->forget('x');

        self::assertNull($this->repository()->findTouchedAt('x'));
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
