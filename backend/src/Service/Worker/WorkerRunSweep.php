<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\Exception\ApiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Recommendation\RecommendationRunAdvancer;
use App\Service\Recommendation\TickDriver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * One worker-regime sweep over every active recommendation run (#371): mark
 * the sweeping worker's liveness, advance each run once at TickDriver::Worker
 * behind the per-run error ladder, and leave the identity map clean. Shared
 * by the worker's ten-second AdvanceRecommendationRuns firing and the
 * on-demand drain command -- both ARE the worker regime, which is why
 * liveness is marked here. ForYouSweep::sweepOnce() is NOT a third copy of
 * this: the cron/poll sweep runs the Poll regime and marks no liveness at all
 * -- it is not a background worker.
 *
 * Which regime is sweeping is the caller's business, not this class's, so the
 * caller names its own RecommendationDriverKind rather than this class
 * picking a heartbeat key.
 */
final readonly class WorkerRunSweep
{
    public function __construct(
        private RecommendationRunRepository $runs,
        private RecommendationRunAdvancer $advancer,
        private WorkerPresence $presence,
        private SweepStreamHeartbeat $heartbeat,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Returns how many active runs this sweep attempted, whether or not the
     * attempt succeeded: the drain command loops until a sweep finds nothing
     * to attempt, and a failed attempt still made progress (the advancer
     * recorded the failure against the run, which will drop out of
     * findAllActive() once its failure ceiling is hit).
     */
    public function sweep(RecommendationDriverKind $kind): int
    {
        $attemptedRuns = 0;
        // Arms the mid-call heartbeat: marking between runs is not enough once
        // a single call can outlast the freshness window (#433).
        $this->heartbeat->sweepStarted($kind);

        try {
            $activeRuns = $this->runs->findAllActive();

            if ([] === $activeRuns) {
                // Marked with nothing to do as well: the heartbeat is the
                // liveness signal the poll driver defers to, not a work log,
                // and an idle worker is still a worker.
                $this->presence->mark($kind);

                return 0;
            }

            foreach ($activeRuns as $run) {
                // Before each run, because a sweep's duration is the SUM over
                // its runs and one run can spend a whole provider timeout.
                // Marking only once per sweep let the heartbeat go stale
                // mid-sweep, and the client then took the healthy worker for
                // a dead one (#311 final review, Critical 2).
                $this->presence->mark($kind);
                $this->advanceOne($run);
                ++$attemptedRuns;
            }
        } finally {
            // A long-running caller accumulates managed entities across
            // sweeps; the identity map is per-sweep state, not process
            // state. `finally` rather than a plain trailing call, so this
            // still runs even if something above ever escapes advanceOne()'s
            // own floor (#311 fix round 2) -- the identity map must never be
            // left dirty for the *next* sweep just because this one had a
            // run go wrong.
            $this->entityManager->clear();
            $this->heartbeat->sweepEnded();
        }

        return $attemptedRuns;
    }

    /**
     * The typed AI-provider cases are handled by exception type alone --
     * neither needs the run passed back out, because each case already
     * knows everything it needs to do. AiNotConfiguredException and
     * ApiKeyUnreadableException are no longer classified here at all: the
     * shared tick both drivers call (RecommendationRunAdvancer::tick(),
     * #311 fix) already failed and flushed the run before rethrowing, so
     * there is nothing left to record. That failure recording used to live
     * here too, split into "which failure to record" (classifyFailure) and
     * "record it"; duplicating that classification in only one driver is
     * exactly what left a poll-only install's run stuck forever, so it now
     * lives in the one place both drivers go through.
     */
    private function advanceOne(RecommendationRun $run): void
    {
        try {
            $this->advancer->advance($run->getUser(), TickDriver::Worker);
        } catch (AiNotConfiguredException | ApiKeyUnreadableException) {
            // Already failed and flushed by the shared tick; nothing to do.
        } catch (ProviderUnreachableException | CredentialsRejectedException $e) {
            // The advancer already counted this against the run's own
            // transport-failure ceiling; the sweep just moves on and the next
            // firing retries. One user's dead provider must not fail the
            // message and starve every other user's run.
            $this->logger->warning('Recommendation sweep: provider call failed.', [
                'runId' => $run->getId(),
                'exception' => $e,
            ]);
        } catch (\Throwable $e) {
            // The floor beneath every case above: nothing that goes wrong
            // advancing one run may ever abort the sweep for every run
            // sorted after it. Logged at error level because, unlike the
            // typed cases above, nothing here already recorded the failure
            // anywhere else.
            $this->logger->error('Recommendation sweep: unexpected failure advancing a run.', [
                'runId' => $run->getId(),
                'exception' => $e,
            ]);
        }
    }
}
