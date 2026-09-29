<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Exception\AiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\RecommendationRunAdvancer;
use App\Service\Recommendation\Run\SweepStreamHeartbeat;
use App\Service\Recommendation\Run\WorkerPresence;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * One worker-regime sweep over every active run, for the worker's firing and the drain command. ForYouSweep must not
 * call it: the cron pass runs inside a web request, so it advances at TickDriver::Sweep, clamped like a poll.
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
     * Counts attempted runs, failed ones included: the drain command loops until a sweep attempts none, and a failed
     * run drops out of findAllActive() once it reaches its failure ceiling.
     */
    public function sweep(RecommendationDriverKind $kind): int
    {
        $attemptedRuns = 0;
        // Arms the mid-call heartbeat: one provider call can outlast the freshness window between two marks.
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
                // Before each run: a sweep lasts the sum of its runs, and one run can spend a whole provider
                // timeout, so one mark per sweep goes stale and the client takes the working worker for dead.
                $this->presence->mark($kind);
                $this->advanceOne($run);
                ++$attemptedRuns;
            }
        } finally {
            // Per-sweep state: cleared in `finally`, so even a failure past advanceOne()'s floor never leaves the
            // map dirty for the next sweep of a long-running caller.
            $this->entityManager->clear();
            $this->heartbeat->sweepEnded();
        }

        return $attemptedRuns;
    }

    private function advanceOne(RecommendationRun $run): void
    {
        try {
            $this->advancer->advance($run->getUser(), TickDriver::Worker);
        } catch (AiNotConfiguredException | AiKeyUnreadableException) {
            // Already failed and flushed by RecommendationRunAdvancer::tick(); nothing to do.
        } catch (ProviderUnreachableException | CredentialsRejectedException $exception) {
            // Already counted against the run's transport-failure ceiling; the next firing retries. One user's dead
            // provider must not fail the message and starve every other user's run.
            $this->logger->warning('Recommendation sweep: provider call failed.', [
                'runId' => $run->getId(),
                'exception' => $exception,
            ]);
        } catch (\Throwable $exception) {
            // The floor: nothing that goes wrong with one run may abort the sweep for the runs after it. Error level,
            // because unlike the typed cases above nothing has recorded this failure yet.
            $this->logger->error('Recommendation sweep: unexpected failure advancing a run.', [
                'runId' => $run->getId(),
                'exception' => $exception,
            ]);
        }
    }
}
