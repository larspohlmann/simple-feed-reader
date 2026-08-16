<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\WorkerHeartbeat;
use App\Repository\WorkerHeartbeatRepository;
use App\Service\Ai\ProviderTimeouts;
use App\Service\Worker\RecommendationDriverKind;
use App\Service\Worker\WorkerPresence;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

final class WorkerPresenceTest extends DbTestCase
{
    public function testContainerWiringMarksAndReportsAlive(): void
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        $presence->mark(RecommendationDriverKind::PersistentWorker);

        self::assertTrue($presence->hasPersistentRecommendationWorker());
    }

    public function testNoHeartbeatMeansNoWorker(): void
    {
        self::assertFalse($this->presenceAt('2026-08-07 12:00:00')->hasPersistentRecommendationWorker());
    }

    public function testAFreshHeartbeatMeansAlive(): void
    {
        $this->repository()->touch(
            RecommendationDriverKind::PersistentWorker->heartbeatName(),
            new \DateTimeImmutable('2026-08-07 11:59:40'),
        );

        self::assertTrue($this->presenceAt('2026-08-07 12:00:00')->hasPersistentRecommendationWorker());
    }

    public function testTheHeartbeatIsAliveExactlyUpToTheEdgeOfTheWindow(): void
    {
        $this->repository()->touch(
            RecommendationDriverKind::PersistentWorker->heartbeatName(),
            $this->secondsBeforeNoon(WorkerPresence::FRESH_SECONDS),
        );

        self::assertTrue($this->presenceAt('2026-08-07 12:00:00')->hasPersistentRecommendationWorker());
    }

    public function testOneSecondPastTheWindowIsDead(): void
    {
        $this->repository()->touch(
            RecommendationDriverKind::PersistentWorker->heartbeatName(),
            $this->secondsBeforeNoon(WorkerPresence::FRESH_SECONDS + 1),
        );

        self::assertFalse($this->presenceAt('2026-08-07 12:00:00')->hasPersistentRecommendationWorker());
    }

    /**
     * The invariant the whole arbitration rests on, pinned as a RELATIONSHIP
     * rather than as a number: a window narrower than the longest silence a
     * healthy worker can produce declares that worker dead. It did — 30 s
     * against a 120 s ceiling — and the client then read the per-user lock as
     * a user-facing failure and stopped polling a healthy run (#311 final
     * review, Critical 2). Asserting a literal would have proved nothing;
     * this fails the moment either bound moves the wrong way.
     *
     * The longest silence used to be a whole provider call, because the sweep
     * touched the heartbeat only between runs. SweepStreamHeartbeat now pings
     * it from the transport as chunks arrive (#433), so what is left is the
     * wait before the first chunk — and the widest that can be is the most
     * patient first-byte bound any connection may be configured with.
     */
    public function testTheFreshnessWindowOutlastsTheLongestSilenceBeforeAnAnswer(): void
    {
        self::assertGreaterThan(
            ProviderTimeouts::forSlowModel()->firstByteSeconds,
            WorkerPresence::FRESH_SECONDS,
            'A worker waiting for a slow model\'s first token must still count as alive.',
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
            ProviderTimeouts::forSlowModel()->firstByteSeconds + $sweepIntervalSeconds,
            WorkerPresence::FRESH_SECONDS,
        );
    }

    /**
     * The window is deliberately NOT sized against the wall clock any more. A
     * slow connection may hold one call for an hour, and a freshness window
     * that covered it would believe a dead worker for that hour. This pins
     * that the streaming heartbeat is what carries the difference — if the
     * window were ever re-derived from the wall clock, it would have to grow
     * past this bound.
     */
    public function testTheFreshnessWindowIsNotSizedAgainstAWholeCall(): void
    {
        self::assertLessThan(
            ProviderTimeouts::forSlowModel()->wallClockSeconds,
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

    /**
     * "Somebody is driving" is asked of every driver kind, not of a named
     * pair: a kind added later must count without anyone remembering to
     * extend an OR chain. A live drainer alone is the case that proves the
     * question is not simply the persistent worker's.
     */
    public function testALiveDrainerAloneCountsAsSomebodyDriving(): void
    {
        $this->repository()->touch(
            RecommendationDriverKind::OnDemandDrainer->heartbeatName(),
            $this->secondsBeforeNoon(WorkerPresence::FRESH_SECONDS),
        );

        $presence = $this->presenceAt('2026-08-07 12:00:00');
        self::assertTrue($presence->isAnybodyDrivingRecommendationRuns());
        self::assertFalse($presence->hasPersistentRecommendationWorker());
    }

    public function testAStaleHeartbeatOfEveryKindMeansNobodyIsDriving(): void
    {
        foreach (RecommendationDriverKind::cases() as $kind) {
            $this->repository()->touch(
                $kind->heartbeatName(),
                $this->secondsBeforeNoon(WorkerPresence::FRESH_SECONDS + 1),
            );
        }

        self::assertFalse($this->presenceAt('2026-08-07 12:00:00')->isAnybodyDrivingRecommendationRuns());
    }

    public function testMarkingTheDrainerLeavesThePersistentWorkersKeyUntouched(): void
    {
        $presence = $this->presenceAt('2026-08-07 12:00:00');

        $presence->mark(RecommendationDriverKind::OnDemandDrainer);

        self::assertNull(
            $this->repository()->findTouchedAt(RecommendationDriverKind::PersistentWorker->heartbeatName()),
        );
    }

    public function testForgettingTheDrainerRemovesItsRow(): void
    {
        $presence = $this->presenceAt('2026-08-07 12:00:00');
        $presence->mark(RecommendationDriverKind::OnDemandDrainer);

        $presence->forget(RecommendationDriverKind::OnDemandDrainer);

        self::assertFalse($presence->isAnybodyDrivingRecommendationRuns());
    }

    /**
     * The persistent worker's key is the settings card's only evidence, and
     * its owner is a process nobody can ask whether it is still there — so
     * clearing it is refused rather than merely avoided by every current
     * caller.
     */
    public function testThePersistentWorkersKeyCannotBeSurrendered(): void
    {
        $presence = $this->presenceAt('2026-08-07 12:00:00');
        $presence->mark(RecommendationDriverKind::PersistentWorker);

        $this->expectException(\LogicException::class);

        try {
            $presence->forget(RecommendationDriverKind::PersistentWorker);
        } finally {
            self::assertTrue($presence->hasPersistentRecommendationWorker());
        }
    }

    /**
     * The poll path asks about every driver kind on every request from every
     * open tab, so the read is one query rather than one per name. Every name
     * that has a row comes back -- a read that stopped at the first would let
     * a live drainer go unnoticed behind a dead worker -- and names without a
     * row are absent rather than null, which is what lets the caller treat
     * "present" as "has a touch instant".
     */
    public function testTheBatchedHeartbeatReadReturnsEveryNameThatHasARow(): void
    {
        $firstTouchedAt = new \DateTimeImmutable('2026-08-07 11:00:00');
        $secondTouchedAt = new \DateTimeImmutable('2026-08-07 11:30:00');
        $this->repository()->touch('first-present', $firstTouchedAt);
        $this->repository()->touch('second-present', $secondTouchedAt);

        self::assertEquals(
            ['first-present' => $firstTouchedAt, 'second-present' => $secondTouchedAt],
            $this->repository()->findTouchedAtByNames(['first-present', 'second-present', 'absent']),
        );
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
