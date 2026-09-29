<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Recommendation\Run\Model\ForYouSweepReportModel;
use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Service\Recommendation\Run\Model\TickDriver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Scheduled "For you": startDueRuns() for the worker; sweepOnce() for the cron, which also advances each active run one
 * Sweep tick. While sweeping it holds its own liveness key, surrendered when the sweep ends.
 */
final readonly class ForYouSweep
{
    public function __construct(
        private DueRecommendationRunFinder $finder,
        private RecommendationRunStarter $starter,
        private RecommendationRunAdvancer $advancer,
        private RecommendationRunRepository $runs,
        private WorkerPresence $presence,
        private SweepStreamHeartbeat $heartbeat,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function startDueRuns(): int
    {
        $started = 0;

        foreach ($this->finder->due() as $user) {
            try {
                $this->starter->start($user);
                ++$started;
            } catch (AiNotConfiguredException) {
                // The configuration changed since the finder's check: skip this account, not the sweep.
            }
        }

        return $started;
    }

    public function sweepOnce(): ForYouSweepReportModel
    {
        $startedRuns = $this->startDueRuns();
        $advancedRuns = $this->advanceEveryActiveRunAsTheDriver();

        // The identity map is per-sweep state, not request state; clear it so
        // the remaining-active count below is a fresh read from the database.
        $this->entityManager->clear();

        return new ForYouSweepReportModel($startedRuns, $advancedRuns, \count($this->runs->findAllActive()));
    }

    /**
     * Marks the cron key before each run and beats it mid-call. The key is surrendered in `finally` and, for a request
     * the gateway kills (Strato's 240 s cap), by a shutdown hook: a stale key would keep the poll tick and the drain
     * spawner from recovering the run for FRESH_SECONDS.
     */
    private function advanceEveryActiveRunAsTheDriver(): int
    {
        $advancedRuns = 0;
        $this->surrenderTheCronSweepKeyIfTheRequestIsKilled();
        $this->heartbeat->sweepStarted(RecommendationDriverKind::CronSweep);

        try {
            foreach ($this->runs->findAllActive() as $run) {
                $this->presence->mark(RecommendationDriverKind::CronSweep);
                $advancedRuns += $this->advanceOne($run);
            }
        } finally {
            $this->heartbeat->sweepEnded();
            $this->surrenderTheCronSweepKey();
        }

        return $advancedRuns;
    }

    /**
     * Registered before the first mark, so the key never exists without a hook to take it back. Running it after the
     * `finally` is harmless: forgetting a forgotten name is a no-op. A kill skipping shutdown handlers still ages out.
     */
    private function surrenderTheCronSweepKeyIfTheRequestIsKilled(): void
    {
        register_shutdown_function(function (): void {
            $this->surrenderTheCronSweepKey();
        });
    }

    /** Never throws: from `finally` it would mask the pass's own failure, from the shutdown hook add a second fatal. */
    private function surrenderTheCronSweepKey(): void
    {
        try {
            $this->presence->forget(RecommendationDriverKind::CronSweep);
        } catch (\Throwable) {
            // Deliberately silent: see this method's doc comment.
        }
    }

    private function advanceOne(RecommendationRun $run): int
    {
        try {
            $this->advancer->advance($run->getUser(), TickDriver::Sweep);

            return 1;
        } catch (\Throwable $exception) {
            // The advancer already recorded the failure on the run; one broken provider must not stop the others.
            $this->logger->warning('For You sweep: advancing a run failed.', [
                'runId' => $run->getId(),
                'exception' => $exception,
            ]);

            return 0;
        }
    }
}
