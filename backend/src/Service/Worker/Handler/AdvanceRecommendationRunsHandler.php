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

        foreach ($this->runs->findAllActive() as $run) {
            $this->advanceOne($run);
        }

        // A long-running consumer accumulates managed entities across sweeps;
        // the identity map is per-firing state, not worker state.
        $this->entityManager->clear();
    }

    private function advanceOne(RecommendationRun $run): void
    {
        try {
            $this->advancer->advance($run->getUser());
        } catch (AiNotConfiguredException) {
            // The account lost its provider or model mid-run. The run can
            // never advance again on any driver, so fail it rather than sweep
            // over the same exception every ten seconds forever.
            $run->fail('The AI provider is no longer configured.', $this->clock->now());
            $this->entityManager->flush();
        } catch (ApiKeyUnreadableException) {
            // Same shape: the advancer cannot even build credentials, and no
            // amount of retrying fixes a key only the user can re-enter.
            $run->fail('The stored API key can no longer be read.', $this->clock->now());
            $this->entityManager->flush();
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
            // The floor beneath the typed cases above: an exception this
            // handler did not anticipate must still never abort the firing
            // for every run sorted after this one (#311 fix round 1). Unlike
            // the typed cases, nothing here already recorded the failure
            // anywhere else, so this is logged at error level.
            $this->logger->error('Recommendation sweep: unexpected failure advancing a run.', [
                'runId' => $run->getId(),
                'exception' => $e,
            ]);
        }
    }
}
