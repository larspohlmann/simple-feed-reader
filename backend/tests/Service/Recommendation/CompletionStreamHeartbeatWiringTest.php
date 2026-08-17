<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\WorkerHeartbeat;
use App\Service\Recommendation\CompletionStreamHeartbeat;
use App\Service\Recommendation\TickLockKeepalive;
use App\Service\Worker\RecommendationDriverKind;
use App\Service\Worker\SweepStreamHeartbeat;
use App\Tests\DbTestCase;
use App\Tests\Support\RefreshCountingLock;

/**
 * This whole mechanism (#444) rests on `$container->get(TickLockKeepalive::class)`
 * being the SAME instance the transport beats through the
 * `CompletionStreamHeartbeat` alias -- and, since #433, on
 * `SweepStreamHeartbeat` staying that same instance too. Every other test in
 * this suite builds these classes by hand, so none of them can catch a
 * wiring regression: if a future edit to services.yaml ever pointed the
 * composite at a *different* TickLockKeepalive than the one the advancer
 * arms by class name, arming one instance and beating another would be a
 * silent no-op. The lock would stop refreshing, the three-hour stall #439
 * and #444 exist to fix would come straight back, and every unit test would
 * stay green, because none of them go through the container. Only driving
 * the real, compiled container proves the identity holds.
 */
final class CompletionStreamHeartbeatWiringTest extends DbTestCase
{
    public function testArmingTheKeepaliveThroughTheContainerRefreshesItsLock(): void
    {
        /** @var TickLockKeepalive $keepalive */
        $keepalive = self::getContainer()->get(TickLockKeepalive::class);
        $lock = new RefreshCountingLock();
        $keepalive->hold($lock, 'recommendation-run-1');

        /** @var CompletionStreamHeartbeat $heartbeat */
        $heartbeat = self::getContainer()->get(CompletionStreamHeartbeat::class);
        $heartbeat->beat();

        self::assertSame(1, $lock->refreshCount());

        $keepalive->release();
    }

    public function testArmingTheSweepStreamHeartbeatThroughTheContainerMarksPresence(): void
    {
        /** @var SweepStreamHeartbeat $sweepHeartbeat */
        $sweepHeartbeat = self::getContainer()->get(SweepStreamHeartbeat::class);
        $sweepHeartbeat->sweepStarted(RecommendationDriverKind::PersistentWorker);

        /** @var CompletionStreamHeartbeat $heartbeat */
        $heartbeat = self::getContainer()->get(CompletionStreamHeartbeat::class);
        $heartbeat->beat();

        self::assertNotNull($this->persistentWorkerTouchedAt());

        $sweepHeartbeat->sweepEnded();
    }

    private function persistentWorkerTouchedAt(): ?\DateTimeImmutable
    {
        $this->em->clear();
        $heartbeat = $this->em->getRepository(WorkerHeartbeat::class)
            ->find(RecommendationDriverKind::PersistentWorker->heartbeatName());

        return $heartbeat?->getTouchedAt();
    }
}
