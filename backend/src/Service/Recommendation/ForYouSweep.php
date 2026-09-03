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
 * `startDueRuns()` is the worker's half: on a worker-equipped install the ten-second
 * AdvanceRecommendationRuns sweep drives started runs to the finish, so the worker only
 * starts them. `sweepOnce()` is the cron half: a worker-less install has no advance
 * sweep, so one call both starts due runs and advances every active run one tick. Each
 * tick now sends a bounded wave of concurrent calls, not a single one (#344) —
 * advanceOne() passes TickDriver::Sweep, which effectiveCap() clamps exactly like a
 * poll tick (`min(cap, POLL_MAX_CONCURRENCY)`), because this call, like a browser poll,
 * runs inside a bounded web request (the cron hits it over HTTP), not a worker's
 * long-lived process. The advancer flushes once the wave resolves, so a request the
 * gateway kills still leaves committed progress and the next call resumes.
 *
 * A bounded request it may be, but while it advances runs it IS the install's driver,
 * and says so under its own liveness key (#439). It used to mark none: a browser polling
 * the account the sweep was working on then read a held lock with no driver behind it
 * and was told its healthy run had stalled. The key is surrendered the moment the sweep
 * is over, as the drain command surrenders its own, and on the killed request too — a
 * poll tick between two cron passes must go on driving the run itself, the whole point
 * of a worker-less install.
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
     * One pass over the active runs, under this sweep's liveness key, the way
     * WorkerRunSweep runs its own: marked before each run, because a sweep's duration is
     * the SUM over its runs and one can spend a whole provider timeout; beating mid-call
     * too, because a single streamed call can outlast WorkerPresence::FRESH_SECONDS (#433).
     *
     * The key is surrendered twice over, because the pass owns it exactly as long as it
     * is driving and there are two ways to stop. `finally` covers every ending that
     * unwinds the stack, but NOT the gateway killing the request, which here is routine
     * (Strato caps a web request at 240 s and /maintenance/tick is what the cron calls),
     * so a shutdown hook covers that one. Both are needed: a kill mid-advanceOne() has
     * already written the key, and leaving it fresh for the rest of
     * WorkerPresence::FRESH_SECONDS suppresses the very paths that recover the run — the
     * poll tick (demotes to a status read) and the drain spawner (declines to fork) —
     * for sixteen minutes.
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
     * Registered before the first mark, so no instant exists in which the key can exist
     * without something registered to take it back. This is the same net
     * RecommendationRunAdvancer puts under its per-user lock and RecommendationDrainCommand
     * under its own liveness key, for the same ending.
     *
     * Deliberately unguarded against running twice: on every ordinary pass both this and
     * the `finally` surrender the key, and forgetting an already-forgotten name is a
     * documented no-op ({@see \App\Repository\WorkerHeartbeatRepository::forget()}). The
     * flag RecommendationDrainCommand carries buys nothing here, because its hook also
     * releases a lock.
     *
     * What still defeats it: a kill that skips PHP's shutdown handlers — a SIGKILL, an
     * OOM kill, a crashing extension. The key then ages out over FRESH_SECONDS, the
     * behaviour this hook exists to stop being the normal case.
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
