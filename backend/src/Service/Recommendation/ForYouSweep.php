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
 * `startDueRuns()` is the worker's half: on a worker-equipped install the
 * ten-second AdvanceRecommendationRuns sweep drives the started runs to the
 * finish, so the worker only needs to start them. `sweepOnce()` is the cron
 * half: a worker-less install has no advance sweep, so one call both starts
 * due runs and advances every active run once. It advances one tick per run,
 * and each tick now sends a bounded wave of concurrent provider calls rather
 * than a single one (#344) — advanceOne() passes TickDriver::Sweep, which
 * RecommendationRunAdvancer::effectiveCap() clamps exactly like a poll tick
 * (`min(cap, POLL_MAX_CONCURRENCY)`, i.e. `min(cap, 2)`): this call, like a
 * browser poll, runs inside a bounded web request (the maintenance cron hits
 * this over HTTP), not inside a worker's own long-lived process. The advancer
 * flushes once the wave resolves, so a request the gateway kills still leaves
 * committed progress and the next call resumes.
 *
 * A bounded request it may be, but while it advances runs it IS the install's
 * driver, and it says so under its own liveness key (#439). It used to mark
 * none: a browser polling the account the sweep was working on then read a
 * held lock with no driver behind it and was told its healthy run had stalled.
 * The key is surrendered the moment the sweep is over, exactly as the drain
 * command surrenders its own -- a poll tick between two cron passes must go on
 * driving the run itself, which is the whole point of a worker-less install.
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
     * WorkerRunSweep runs its own: marked before each run, because a sweep's
     * duration is the SUM over its runs and one of them can spend a whole
     * provider timeout; beating mid-call as well, because a single streamed
     * call can outlast WorkerPresence::FRESH_SECONDS on its own (#433).
     *
     * The key is surrendered in `finally`, so the pass owns it for exactly as
     * long as it is driving. A request the gateway kills never gets here and
     * leaves the key to age out instead -- the same exposure the drain command
     * accepts, and shorter than the wait for the next cron pass.
     */
    private function advanceEveryActiveRunAsTheDriver(): int
    {
        $advancedRuns = 0;
        $this->heartbeat->sweepStarted(RecommendationDriverKind::CronSweep);

        try {
            foreach ($this->runs->findAllActive() as $run) {
                $this->presence->mark(RecommendationDriverKind::CronSweep);
                $advancedRuns += $this->advanceOne($run);
            }
        } finally {
            $this->heartbeat->sweepEnded();
            $this->presence->forget(RecommendationDriverKind::CronSweep);
        }

        return $advancedRuns;
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
