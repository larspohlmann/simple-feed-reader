<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\WorkerHeartbeat;
use App\Repository\WorkerHeartbeatRepository;
use App\Service\Worker\RecommendationDriverKind;
use App\Service\Worker\SweepStreamHeartbeat;
use App\Service\Worker\WorkerPresence;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The mechanism that lets WorkerPresence::FRESH_SECONDS stay short while a
 * single provider call runs for an hour (#433).
 */
final class SweepStreamHeartbeatTest extends DbTestCase
{
    public function testItWritesNothingUntilASweepArmsIt(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $heartbeat = new SweepStreamHeartbeat($this->presence($clock), $clock);

        $heartbeat->beat();

        self::assertNull($this->touchedAt(RecommendationDriverKind::PersistentWorker));
    }

    public function testItMarksTheSweepingKindOnTheFirstBeat(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $heartbeat = new SweepStreamHeartbeat($this->presence($clock), $clock);

        $heartbeat->sweepStarted(RecommendationDriverKind::PersistentWorker);
        $heartbeat->beat();

        self::assertEquals(
            new \DateTimeImmutable('2026-08-16 12:00:00'),
            $this->touchedAt(RecommendationDriverKind::PersistentWorker),
        );
    }

    /**
     * A streamed answer delivers deltas many times a second and each write is
     * a row update, so the beats are throttled. The throttle has to stay far
     * below FRESH_SECONDS, which the interval between these two beats is.
     */
    public function testItThrottlesWritesAndResumesOnceTheIntervalHasPassed(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $heartbeat = new SweepStreamHeartbeat($this->presence($clock), $clock);
        $heartbeat->sweepStarted(RecommendationDriverKind::PersistentWorker);

        $heartbeat->beat();
        $clock->sleep(5);
        $heartbeat->beat();

        self::assertEquals(
            new \DateTimeImmutable('2026-08-16 12:00:00'),
            $this->touchedAt(RecommendationDriverKind::PersistentWorker),
            'A beat five seconds after the last write must not cost a second one.',
        );

        $clock->sleep(60);
        $heartbeat->beat();

        self::assertEquals(
            new \DateTimeImmutable('2026-08-16 12:01:05'),
            $this->touchedAt(RecommendationDriverKind::PersistentWorker),
        );
    }

    /**
     * A sweep that has ended is no longer evidence of anything. The drain
     * command surrenders its liveness key when it exits, and a heartbeat left
     * armed would write that key straight back.
     */
    public function testItStopsWritingOnceTheSweepEnds(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $heartbeat = new SweepStreamHeartbeat($this->presence($clock), $clock);

        $heartbeat->sweepStarted(RecommendationDriverKind::PersistentWorker);
        $heartbeat->beat();
        $heartbeat->sweepEnded();
        $clock->sleep(600);
        $heartbeat->beat();

        self::assertEquals(
            new \DateTimeImmutable('2026-08-16 12:00:00'),
            $this->touchedAt(RecommendationDriverKind::PersistentWorker),
        );
    }

    /**
     * The drainer and the persistent worker keep separate keys on purpose —
     * only one of them answers "does this install run a worker?" — so a beat
     * must mark the kind that armed it and no other.
     */
    public function testItMarksOnlyTheKindThatArmedIt(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $heartbeat = new SweepStreamHeartbeat($this->presence($clock), $clock);

        $heartbeat->sweepStarted(RecommendationDriverKind::OnDemandDrainer);
        $heartbeat->beat();

        self::assertNotNull($this->touchedAt(RecommendationDriverKind::OnDemandDrainer));
        self::assertNull($this->touchedAt(RecommendationDriverKind::PersistentWorker));
    }

    private function presence(MockClock $clock): WorkerPresence
    {
        /** @var WorkerHeartbeatRepository $heartbeats */
        $heartbeats = self::getContainer()->get(WorkerHeartbeatRepository::class);

        return new WorkerPresence($heartbeats, $clock);
    }

    private function touchedAt(RecommendationDriverKind $kind): ?\DateTimeImmutable
    {
        $this->em->clear();
        $heartbeat = $this->em->getRepository(WorkerHeartbeat::class)->find($kind->heartbeatName());

        return $heartbeat?->getTouchedAt();
    }
}
