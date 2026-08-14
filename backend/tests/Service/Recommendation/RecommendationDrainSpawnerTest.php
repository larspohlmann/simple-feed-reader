<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Process\DetachedProcessLauncherInterface;
use App\Service\Recommendation\RecommendationDrainSpawner;
use App\Service\Worker\WorkerPresence;
use App\Tests\DbTestCase;

final class RecommendationDrainSpawnerTest extends DbTestCase
{
    public function testSpawnsTheDetachedDrainerWhenNoWorkerIsAlive(): void
    {
        $launcher = $this->recordingLauncher();

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
        $launcher = $this->recordingLauncher();
        $this->presence()->markRecommendationSweep();

        (new RecommendationDrainSpawner($this->presence(), $launcher))->spawnIfNoWorker();

        self::assertSame([], $launcher->launches);
    }

    /**
     * @return DetachedProcessLauncherInterface&object{launches: list<list<string>>}
     */
    private function recordingLauncher(): DetachedProcessLauncherInterface
    {
        return new class implements DetachedProcessLauncherInterface {
            /** @var list<list<string>> */
            public array $launches = [];

            public function launch(string $consoleCommandName, string ...$arguments): void
            {
                $this->launches[] = array_values([$consoleCommandName, ...$arguments]);
            }
        };
    }

    private function presence(): WorkerPresence
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        return $presence;
    }
}
