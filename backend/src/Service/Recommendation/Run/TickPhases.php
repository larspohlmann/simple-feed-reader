<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Enum\RunStatus;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use Symfony\Component\Clock\ClockInterface;

final readonly class TickPhases
{
    public function __construct(
        private SnapshotPhase $snapshot,
        private DistillationPhase $distillation,
        private BatchPhase $batch,
        private ConsolidationPhase $consolidation,
        private RecommendationRunDeferral $deferral,
        private RecommendationTransportFailureRecorder $transportFailures,
        private ClockInterface $clock,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReport
    {
        $run = $tick->run;
        if (RunStatus::Pending === $run->getStatus()) {
            return $this->snapshot->advance($tick);
        }

        if ($run->mustWaitBeforeRetry($this->clock->now())) {
            return RecommendationRunReport::fromRun($run);
        }

        return $this->advanceWithinTheEnvelope($this->providerPhaseFor($run), $tick);
    }

    private function providerPhaseFor(RecommendationRun $run): ProviderPhase
    {
        $progress = $run->progress();

        return match (true) {
            $progress->distillPending => $this->distillation,
            $progress->isConsolidationPhase => $this->consolidation,
            default => $this->batch,
        };
    }

    private function advanceWithinTheEnvelope(ProviderPhase $phase, TickContext $tick): RecommendationRunReport
    {
        try {
            return $phase->advance($tick);
        } catch (ProviderRateLimitedException $e) {
            return $this->deferral->defer($tick->run, $e);
        } catch (ProviderUnreachableException | CredentialsRejectedException | RetryableProviderException $e) {
            $this->transportFailures->record($tick->run, $tick->connection, $e->getMessage());

            throw $e;
        }
    }
}
