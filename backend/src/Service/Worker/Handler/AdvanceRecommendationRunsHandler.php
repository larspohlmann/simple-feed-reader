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
use Symfony\Component\Clock\ClockInterface;
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
        private ClockInterface $clock,
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
     * One try/catch, not one per typed case: the earlier version flushed
     * inside each typed catch, so a flush failure (lock timeout, dropped
     * connection) threw from *within* a catch block -- which PHP never
     * routes to a sibling catch -- and escaped this method exactly like the
     * unanticipated exception the \Throwable floor exists to stop (#311 fix
     * round 2). Splitting "which failure to record" (classifyFailure) from
     * "record it" (the fail()+flush() below, now the one call site for
     * both typed cases) means every path that can throw, including that
     * flush, runs under this single try, with the \Throwable clause as the
     * one floor above all of it.
     */
    private function advanceOne(RecommendationRun $run): void
    {
        try {
            $failureMessage = $this->classifyFailure($run);
            if (null !== $failureMessage) {
                $run->fail($failureMessage, $this->clock->now());
                $this->entityManager->flush();
            }
        } catch (\Throwable $e) {
            // The floor beneath every case above, typed or not: nothing that
            // goes wrong advancing or recording one run may ever abort the
            // firing for every run sorted after it. Logged at error level
            // because, unlike the typed cases below, nothing here already
            // recorded the failure anywhere else.
            $this->logger->error('Recommendation sweep: unexpected failure advancing a run.', [
                'runId' => $run->getId(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * @return non-empty-string|null the message to fail the run with, or null
     *                                if there is nothing to record
     */
    private function classifyFailure(RecommendationRun $run): ?string
    {
        try {
            $this->advancer->advance($run->getUser());

            return null;
        } catch (AiNotConfiguredException) {
            // The account lost its provider or model mid-run. The run can
            // never advance again on any driver, so fail it rather than sweep
            // over the same exception every ten seconds forever.
            return 'The AI provider is no longer configured.';
        } catch (ApiKeyUnreadableException) {
            // Same shape: the advancer cannot even build credentials, and no
            // amount of retrying fixes a key only the user can re-enter.
            return 'The stored API key can no longer be read.';
        } catch (ProviderUnreachableException | CredentialsRejectedException $e) {
            // The advancer already counted this against the run's own
            // transport-failure ceiling; the sweep just moves on and the next
            // firing retries. One user's dead provider must not fail the
            // message and starve every other user's run.
            $this->logger->warning('Recommendation sweep: provider call failed.', [
                'runId' => $run->getId(),
                'exception' => $e,
            ]);

            return null;
        }
    }
}
