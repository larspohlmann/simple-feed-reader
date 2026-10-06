<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
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
    /** A run belongs to the engine and protocol that froze its batches. Failed, not cancelled: switching back resumes. */
    public const string ENGINE_SWITCH = 'This run was started with a different kind of model than the active AI '
        . 'connection uses: an LLM, a decision model and a reranker score articles differently. Start a new run, or '
        . 'switch back to a connection of that kind to resume this one.';

    /** A scoring run also belongs to its model: two models' scores do not compare within one run. */
    public const string SCORING_MODEL_SWITCH = 'This run was started with a different scoring model than the active AI '
        . 'connection uses, and scores from two models cannot be compared within one run. Start a new run, or switch '
        . 'back to that model to resume this one.';

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

        if (self::switchedEngines($run, $tick)) {
            return $this->runFailure->fail($run, self::ENGINE_SWITCH);
        }

        if (self::switchedScoringModels($run, $tick)) {
            return $this->runFailure->fail($run, self::SCORING_MODEL_SWITCH);
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
            return $this->runFailure->failRejected($tick, $exception);
        } catch (ProviderUnreachableException | CredentialsRejectedException | RetryableProviderException $exception) {
            $this->transportFailures->record($tick->run, $tick->connection, $exception->getMessage());

            throw $exception;
        }
    }

    private static function switchedEngines(RecommendationRun $run, TickContext $tick): bool
    {
        return $run->getEngineKind() !== $tick->engineKind
            || $run->getScoringProtocol() !== $tick->scoringProtocol();
    }

    private static function switchedScoringModels(RecommendationRun $run, TickContext $tick): bool
    {
        return null !== $run->getScoringProtocol() && $run->getModel() !== $tick->connection->getModel();
    }
}
