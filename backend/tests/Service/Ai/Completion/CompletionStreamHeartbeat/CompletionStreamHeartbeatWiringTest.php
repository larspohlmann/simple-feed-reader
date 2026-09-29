<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Completion\CompletionStreamHeartbeat;

use App\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface;
use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Service\Recommendation\Run\SweepStreamHeartbeat;
use App\Service\Recommendation\Run\TickLockKeepalive;
use App\Tests\DbTestCase;
use App\Tests\Support\ProvidesWorkerHeartbeats;
use App\Tests\Support\RefreshCountingLock;

/**
 * The TickLockKeepalive and SweepStreamHeartbeat the code arms must be the instances the transport beats through the
 * interface alias. Unit tests build them by hand, so only the compiled container catches a wiring that breaks that.
 */
final class CompletionStreamHeartbeatWiringTest extends DbTestCase
{
    use ProvidesWorkerHeartbeats;

    public function testArmingTheKeepaliveThroughTheContainerRefreshesItsLock(): void
    {
        /** @var TickLockKeepalive $keepalive */
        $keepalive = self::getContainer()->get(TickLockKeepalive::class);
        $lock = new RefreshCountingLock();
        $keepalive->hold($lock, 'recommendation-run-1');

        /** @var CompletionStreamHeartbeatInterface $heartbeat */
        $heartbeat = self::getContainer()->get(CompletionStreamHeartbeatInterface::class);
        $heartbeat->beat();

        self::assertSame(1, $lock->refreshCount());

        $keepalive->release();
    }

    public function testArmingTheSweepStreamHeartbeatThroughTheContainerMarksPresence(): void
    {
        /** @var SweepStreamHeartbeat $sweepHeartbeat */
        $sweepHeartbeat = self::getContainer()->get(SweepStreamHeartbeat::class);
        $sweepHeartbeat->sweepStarted(RecommendationDriverKind::PersistentWorker);

        /** @var CompletionStreamHeartbeatInterface $heartbeat */
        $heartbeat = self::getContainer()->get(CompletionStreamHeartbeatInterface::class);
        $heartbeat->beat();

        self::assertNotNull($this->persistentWorkerTouchedAt());

        $sweepHeartbeat->sweepEnded();
    }

    private function persistentWorkerTouchedAt(): ?\DateTimeImmutable
    {
        return $this->heartbeats()->findTouchedAt(RecommendationDriverKind::PersistentWorker->heartbeatName());
    }
}
