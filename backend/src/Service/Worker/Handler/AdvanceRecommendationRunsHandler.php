<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\Exception\ApiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Recommendation\RecommendationRunAdvancer;
use App\Service\Worker\Message\AdvanceRecommendationRuns;
use App\Service\Worker\WorkerPresence;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The worker side of the driver-agnostic tick (#311): every ten seconds this
 * ticks every active run once, touching the heartbeat the poll driver defers
 * to whether or not there was any work to do.
 */
#[AsMessageHandler]
final readonly class AdvanceRecommendationRunsHandler
{
    public function __construct(
        private RecommendationRunRepository $runs,
        private RecommendationRunAdvancer $advancer,
        private WorkerPresence $presence,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AdvanceRecommendationRuns $message): void
    {
        // Touched every firing, work or not: the heartbeat is the liveness
        // signal the poll driver defers to, not a work log.
        $this->presence->markRecommendationSweep();

        try {
            foreach ($this->runs->findAllActive() as $run) {
                // Again before each run, because a firing's duration is the
                // SUM over its runs and one run can spend a whole provider
                // timeout. Marking only once per firing let the heartbeat go
                // stale mid-firing, and the client then took the healthy
                // worker for a dead one (#311 final review, Critical 2).
                $this->presence->markRecommendationSweep();
                $this->advanceOne($run);
            }
        } finally {
            // A long-running consumer accumulates managed entities across
            // sweeps; the identity map is per-firing state, not worker
            // state. `finally` rather than a plain trailing call, so this
            // still runs even if something above ever escapes advanceOne()'s
            // own floor (#311 fix round 2) -- the identity map must never be
            // left dirty for the *next* firing just because this one had a
            // run go wrong.
            $this->entityManager->clear();
        }
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
            $this->advancer->advance($run->getUser());
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
            // advancing one run may ever abort the firing for every run
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
