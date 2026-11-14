<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Service\Ai\Model\ProviderTimeoutsModel;
use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Service\Recommendation\Run\WorkerPresence;
use App\Tests\DbTestCase;
use App\Tests\Support\ProvidesWorkerHeartbeats;
use Symfony\Component\Clock\MockClock;

final class WorkerPresenceTest extends DbTestCase
{
    use ProvidesWorkerHeartbeats;

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
        $this->heartbeats()->touch(
            RecommendationDriverKind::PersistentWorker->heartbeatName(),
            new \DateTimeImmutable('2026-08-07 11:59:40'),
        );

        self::assertTrue($this->presenceAt('2026-08-07 12:00:00')->hasPersistentRecommendationWorker());
    }

    public function testTheHeartbeatIsAliveExactlyUpToTheEdgeOfTheWindow(): void
    {
        $this->heartbeats()->touch(
            RecommendationDriverKind::PersistentWorker->heartbeatName(),
            $this->secondsBeforeNoon(WorkerPresence::FRESH_SECONDS),
        );

        self::assertTrue($this->presenceAt('2026-08-07 12:00:00')->hasPersistentRecommendationWorker());
    }

    public function testOneSecondPastTheWindowIsDead(): void
    {
        $this->heartbeats()->touch(
            RecommendationDriverKind::PersistentWorker->heartbeatName(),
            $this->secondsBeforeNoon(WorkerPresence::FRESH_SECONDS + 1),
        );

        self::assertFalse($this->presenceAt('2026-08-07 12:00:00')->hasPersistentRecommendationWorker());
    }

    /**
     * Pinned as a relationship, not a number: a window narrower than the longest silence a healthy worker produces,
     * one first-byte wait since the transport pings per chunk, declares that worker dead.
     */
    public function testTheFreshnessWindowOutlastsTheLongestSilenceBeforeAnAnswer(): void
    {
        self::assertGreaterThan(
            ProviderTimeoutsModel::forSlowModel()->firstByteSeconds,
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
            ProviderTimeoutsModel::forSlowModel()->firstByteSeconds + $sweepIntervalSeconds,
            WorkerPresence::FRESH_SECONDS,
        );
    }

    /**
     * The window is not sized against a whole call: a slow connection may hold one for an hour, and a window that
     * covered it would believe a dead worker for that hour. The streaming heartbeat carries the difference.
     */
    public function testTheFreshnessWindowIsNotSizedAgainstAWholeCall(): void
    {
        self::assertLessThan(
            ProviderTimeoutsModel::forSlowModel()->wallClockSeconds,
            WorkerPresence::FRESH_SECONDS,
        );
    }

    private function secondsBeforeNoon(int $seconds): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('2026-08-07 12:00:00'))->modify(sprintf('-%d seconds', $seconds));
    }

    public function testTouchTwiceUpdatesTheOneRow(): void
    {
        $this->heartbeats()->touch('x', new \DateTimeImmutable('2026-08-07 11:00:00'));
        $this->heartbeats()->touch('x', new \DateTimeImmutable('2026-08-07 11:00:10'));

        self::assertEquals(new \DateTimeImmutable('2026-08-07 11:00:10'), $this->heartbeats()->findTouchedAt('x'));
    }

    /**
     * A drainer that dies before its first sweep still reaches the `finally` that forgets its name, so forgetting an
     * untouched name is a no-op.
     */
    public function testForgettingAHeartbeatThatWasNeverTouchedDoesNothing(): void
    {
        $this->heartbeats()->forget('never-touched');

        self::assertNull($this->heartbeats()->findTouchedAt('never-touched'));
    }

    public function testForgettingAHeartbeatRemovesItsRow(): void
    {
        $this->heartbeats()->touch('x', new \DateTimeImmutable('2026-08-07 11:00:00'));

        $this->heartbeats()->forget('x');

        self::assertNull($this->heartbeats()->findTouchedAt('x'));
    }

    /**
     * "Somebody is driving" asks every driver kind, so a kind added later counts: a live drainer alone proves the
     * question is not only the persistent worker's.
     */
    public function testALiveDrainerAloneCountsAsSomebodyDriving(): void
    {
        $this->heartbeats()->touch(
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
            $this->heartbeats()->touch(
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
            $this->heartbeats()->findTouchedAt(RecommendationDriverKind::PersistentWorker->heartbeatName()),
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
     * The persistent worker's key is the settings card's only evidence, and its owner cannot be asked whether it is
     * still there: clearing it is refused, not merely avoided.
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
     * One query for every driver kind: every name with a row comes back, so a live drainer is not hidden behind a
     * dead worker, and a name without a row is absent, not null, so "present" means "has a touch instant".
     */
    public function testTheBatchedHeartbeatReadReturnsEveryNameThatHasARow(): void
    {
        $firstTouchedAt = new \DateTimeImmutable('2026-08-07 11:00:00');
        $secondTouchedAt = new \DateTimeImmutable('2026-08-07 11:30:00');
        $this->heartbeats()->touch('first-present', $firstTouchedAt);
        $this->heartbeats()->touch('second-present', $secondTouchedAt);

        self::assertEquals(
            ['first-present' => $firstTouchedAt, 'second-present' => $secondTouchedAt],
            $this->heartbeats()->findTouchedAtByNames(['first-present', 'second-present', 'absent']),
        );
    }

    private function presenceAt(string $now): WorkerPresence
    {
        return new WorkerPresence($this->heartbeats(), new MockClock($now));
    }
}
