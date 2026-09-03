<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Worker\RecommendationDriverKind;
use App\Service\Worker\SweepStreamHeartbeat;
use App\Service\Worker\WorkerPresence;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The scheduled generation of "For you" (#333), shared by the worker's
 * StartDueRecommendationRuns handler and the maintenance cron endpoint.
 *
 * `startDueRuns()` is the worker's half — the ten-second AdvanceRecommendationRuns
 * sweep drives started runs to the finish there, so the worker only starts them.
 * `sweepOnce()` is the cron half: a worker-less install has no advance sweep, so
 * one call starts due runs and advances every active run one tick, each tick
 * sending a bounded wave of concurrent calls via TickDriver::Sweep, clamped by
 * effectiveCap() like a poll tick because this runs inside a bounded web request,
 * not a worker's long-lived process (#344). The advancer flushes once the wave
 * resolves, so a gateway-killed request still leaves committed progress to resume.
 *
 * While advancing runs it IS the install's driver, under its own liveness key
 * (#439) — unmarked, a browser polling the swept account once read a held lock
 * with no driver behind it and was told its healthy run had stalled. The key is
 * surrendered when the sweep ends, and on a killed request too, so a poll tick
 * between cron passes can still drive the run — the point of a worker-less install.
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
                // The finder already filters unready accounts; this is the
                // defensive floor for a race where the config changed between
                // the query and the start. Skip, do not fail the sweep.
            }
        }

        return $started;
    }

    public function sweepOnce(): ForYouSweepReport
    {
        $startedRuns = $this->startDueRuns();
        $advancedRuns = $this->advanceEveryActiveRunAsTheDriver();

        // The identity map is per-sweep state, not request state; clear it so
        // the remaining-active count below is a fresh read from the database.
        $this->entityManager->clear();

        return new ForYouSweepReport($startedRuns, $advancedRuns, \count($this->runs->findAllActive()));
    }

    /**
     * One pass over the active runs, under this sweep's liveness key — like
     * WorkerRunSweep, marked before each run (a sweep can span a whole provider
     * timeout, summed over its runs) and beaten mid-call too, since one streamed
     * call can outlast WorkerPresence::FRESH_SECONDS (#433).
     *
     * The key is surrendered twice: `finally` covers a normal unwind, but not
     * the gateway killing the request, which is routine here (Strato caps a web
     * request at 240s, and /maintenance/tick is what the cron calls) — a
     * shutdown hook covers that case. Both are needed: a kill mid-advanceOne()
     * has already written the key, and leaving it fresh for FRESH_SECONDS would
     * suppress the paths that recover the run — the poll tick (demotes to a
     * status read) and the drain spawner (declines to fork) — for sixteen
     * minutes.
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
     * Registered before the first mark, so no instant exists where the key
     * could exist without something registered to take it back — the same net
     * RecommendationRunAdvancer puts under its per-user lock and
     * RecommendationDrainCommand under its own liveness key.
     *
     * Deliberately unguarded against running twice: both this and the
     * `finally` surrender the key on an ordinary pass, and forgetting an
     * already-forgotten name is a documented no-op
     * ({@see \App\Repository\WorkerHeartbeatRepository::forget()}). The flag
     * RecommendationDrainCommand carries buys nothing here, since its hook
     * also releases a lock.
     *
     * What still defeats it: a kill that skips PHP's shutdown handlers
     * (SIGKILL, OOM, a crashing extension) — the key then ages out over
     * FRESH_SECONDS, the behaviour this hook exists to stop being normal.
     */
    private function surrenderTheCronSweepKeyIfTheRequestIsKilled(): void
    {
        register_shutdown_function(function (): void {
            $this->surrenderTheCronSweepKey();
        });
    }

    /**
     * Best-effort, for a different reason in each caller: thrown from the `finally` it
     * would REPLACE the failure that ended the pass; thrown from the shutdown hook it
     * would pile a second fatal on whatever ended the request. A failed surrender simply
     * leaves the old behaviour, a key that ages out.
     */
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
            // The advancer already recorded the failure against the run before
            // rethrowing; a broken provider for one account must not abort the
            // sweep for the rest. Log and move on.
            $this->logger->warning('For You sweep: advancing a run failed.', [
                'runId' => $run->getId(),
                'exception' => $exception,
            ]);

            return 0;
        }
    }
}
