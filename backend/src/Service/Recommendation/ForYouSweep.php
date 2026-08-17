<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Exception\AiNotConfiguredException;
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
 */
final readonly class ForYouSweep
{
    public function __construct(
        private DueRecommendationRunFinder $finder,
        private RecommendationRunStarter $starter,
        private RecommendationRunAdvancer $advancer,
        private RecommendationRunRepository $runs,
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

        $advancedRuns = 0;
        foreach ($this->runs->findAllActive() as $run) {
            $advancedRuns += $this->advanceOne($run);
        }

        // The identity map is per-sweep state, not request state; clear it so
        // the remaining-active count below is a fresh read from the database.
        $this->entityManager->clear();

        return new ForYouSweepReport($startedRuns, $advancedRuns, \count($this->runs->findAllActive()));
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
