<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Enum\RunStatus;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Recommendation\Engine\RecommendationEngine\RecommendationEngineInterface;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use Symfony\Component\Clock\ClockInterface;

final readonly class TickPhases
{
    public function __construct(
        private SnapshotPhase $snapshot,
        private RecommendationEngineResolver $engines,
        private RecommendationRunDeferral $deferral,
        private RecommendationTransportFailureRecorder $transportFailures,
        private ClockInterface $clock,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        $run = $tick->run;
        if (RunStatus::Pending === $run->getStatus()) {
            return $this->snapshot->advance($tick);
        }

        if ($run->isRetryDeferredAt($this->clock->now())) {
            return RecommendationRunReportModel::fromRun($run);
        }

        return $this->advanceWithinTheEnvelope($this->engines->engineFor($tick->connection), $tick);
    }

    private function advanceWithinTheEnvelope(
        RecommendationEngineInterface $engine,
        TickContext $tick,
    ): RecommendationRunReportModel {
        try {
            return $engine->advance($tick);
        } catch (ProviderRateLimitedException $exception) {
            return $this->deferral->defer($tick->run, $exception);
        } catch (ProviderUnreachableException | CredentialsRejectedException | RetryableProviderException $exception) {
            $this->transportFailures->record($tick->run, $tick->connection, $exception->getMessage());

            throw $exception;
        }
    }
}
