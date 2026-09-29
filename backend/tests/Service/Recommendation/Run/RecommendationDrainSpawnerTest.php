<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Service\Recommendation\Run\RecommendationDrainSpawner;
use App\Service\Recommendation\Run\WorkerPresence;
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

    /** A fresh worker heartbeat means no spawn: the feature disables itself where a worker already drives. */
    public function testAFreshWorkerHeartbeatSuppressesTheSpawn(): void
    {
        $launcher = new RecordingProcessLauncher();
        $this->presence()->mark(RecommendationDriverKind::PersistentWorker);

        (new RecommendationDrainSpawner($this->presence(), $launcher))->spawnIfNoWorker();

        self::assertSame([], $launcher->launches);
    }

    /**
     * A live drainer holds the drain lock, so a second would boot Symfony only to lose it and exit: the question is
     * "is anybody driving?", not "is there a persistent worker?".
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
