<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\RecommendationDrainSpawner;
use App\Service\Worker\RecommendationDriverKind;
use App\Service\Worker\WorkerPresence;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingProcessLauncher;

final class RecommendationDrainSpawnerTest extends DbTestCase
{
    public function testSpawnsTheDetachedDrainerWhenNoWorkerIsAlive(): void
    {
        $launcher = new RecordingProcessLauncher();

        (new RecommendationDrainSpawner($this->presence(), $launcher))->spawnIfNoWorker();

        self::assertSame([['app:recommendations:drain', '--detach']], $launcher->launches);
    }

    /**
     * The Docker install's real worker keeps the heartbeat fresh, which is
     * exactly what must make the web request never spawn a second driver --
     * the feature self-disables where it is not needed (#371).
     */
    public function testAFreshWorkerHeartbeatSuppressesTheSpawn(): void
    {
        $launcher = new RecordingProcessLauncher();
        $this->presence()->mark(RecommendationDriverKind::PersistentWorker);

        (new RecommendationDrainSpawner($this->presence(), $launcher))->spawnIfNoWorker();

        self::assertSame([], $launcher->launches);
    }

    /**
     * A live drainer is a driver too, and it holds the drain lock: a second
     * one would pay a full Symfony boot only to lose the lock and exit. The
     * spawn question is "is anybody driving?", not "is there a persistent
     * worker?" -- the two answers differ exactly here (#371 follow-up).
     */
    public function testALiveDrainerAlsoSuppressesTheSpawn(): void
    {
        $launcher = new RecordingProcessLauncher();
        $this->presence()->mark(RecommendationDriverKind::OnDemandDrainer);

        (new RecommendationDrainSpawner($this->presence(), $launcher))->spawnIfNoWorker();

        self::assertSame([], $launcher->launches);
    }

    private function presence(): WorkerPresence
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        return $presence;
    }
}
