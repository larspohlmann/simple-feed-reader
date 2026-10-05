<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Enum\RunStatus;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Recommendation\Engine\RecommendationEngine\RecommendationEngineInterface;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use Symfony\Component\Clock\ClockInterface;

final readonly class TickPhases
{
    /**
     * A run belongs to the engine whose batches it froze. Failed, not cancelled: the error says why, and switching back
     * to that connection makes the run resumable where it stopped.
     */
    public const string ENGINE_SWITCH = 'This run was started with a different recommendation engine than the active '
        . 'AI connection uses. Start a new run, or switch back to that connection to resume this one.';

    public function __construct(
        private SnapshotPhase $snapshot,
        private RecommendationEngineResolver $engines,
        private RecommendationRunDeferral $deferral,
        private RecommendationTransportFailureRecorder $transportFailures,
        private RecommendationRunFailure $runFailure,
        private ClockInterface $clock,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        $run = $tick->run;
        if (RunStatus::Pending === $run->getStatus()) {
            return $this->snapshot->advance($tick);
        }

        if ($run->getEngineKind() !== $tick->engineKind) {
            return $this->runFailure->fail($run, self::ENGINE_SWITCH);
        }

        if ($run->isRetryDeferredAt($this->clock->now())) {
            return RecommendationRunReportModel::fromRun($run);
        }

        return $this->advanceWithinTheEnvelope($this->engines->engineOf($tick->engineKind), $tick);
    }

    private function advanceWithinTheEnvelope(
        RecommendationEngineInterface $engine,
        TickContext $tick,
    ): RecommendationRunReportModel {
        try {
            return $engine->advance($tick);
        } catch (ProviderRateLimitedException $exception) {
            return $this->deferral->defer($tick->run, $exception);
        } catch (ProviderRejectedRequestException $exception) {
            return $this->runFailure->fail($tick->run, \sprintf(
                RecommendationTransportFailureRecorder::PROVIDER_FAILED,
                $tick->connection->getBaseUrl(),
                $exception->getMessage(),
            ));
        } catch (ProviderUnreachableException | CredentialsRejectedException | RetryableProviderException $exception) {
            $this->transportFailures->record($tick->run, $tick->connection, $exception->getMessage());

            throw $exception;
        }
    }
}
