<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring;

use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Model\RateLimitedResultModel;
use App\Service\Ai\RateLimitedCalls;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Run\BatchCallOutcome\BatchCallOutcomeInterface;
use App\Service\Recommendation\Run\BatchWave\BatchWaveInterface;
use App\Service\Recommendation\Run\BatchWaveEngine\BatchWaveEngineInterface;
use App\Service\Recommendation\Run\Model\BatchReplyVerdictModel;
use App\Service\Recommendation\Run\Model\CallSlotModel;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\Pass\BatchCall;
use App\Service\Recommendation\Run\RecommendationCallRecorder;
use App\Service\Recommendation\Scoring\Model\ScoringOutcomeModel;
use App\Service\Recommendation\Scoring\Model\ScoringRequestModel;
use App\Service\Recommendation\Scoring\Pass\ScoringWave;

/**
 * One scoring request per batch, each a run-log row.
 *
 * @implements BatchWaveEngineInterface<ScoringWave, ScoringRequestModel, ScoringOutcomeModel>
 */
final readonly class ScoringBatchWave implements BatchWaveEngineInterface
{
    public function __construct(
        private RateLimitedCalls $rateLimitedCalls,
        private ScoringProtocolResolver $protocols,
        private AiProviderConfigurator $configurator,
        private RecommendationCallRecorder $callRecorder,
        private ScoreParser $parser,
    ) {
    }

    /**
     * @param ScoringWave $wave
     *
     * @return BatchCall<ScoringRequestModel>
     */
    public function open(BatchWaveInterface $wave, int $position): BatchCall
    {
        $tick = $wave->tick();
        $protocol = $this->protocols->protocolOf($tick->requireScoringProtocol());
        $waveBatch = $wave->batches()[$position];
        $request = new ScoringRequestModel(
            $tick->connection->getModel() ?? '',
            $wave->reader,
            $protocol->budget($tick->requireScoringContextWindow()),
            $waveBatch->linesInSnapshotOrder(),
        );
        $recordedCall = $this->callRecorder->begin(
            $tick->run,
            CallSlotModel::batch($waveBatch->index + 1),
            $protocol->renderedRequest($request),
        );

        return new BatchCall($request, $recordedCall);
    }

    /**
     * @param ScoringWave                                    $wave
     * @param non-empty-list<BatchCall<ScoringRequestModel>> $calls
     *
     * @return RateLimitedResultModel<ScoringOutcomeModel>
     */
    public function sendAll(BatchWaveInterface $wave, array $calls): RateLimitedResultModel
    {
        $tick = $wave->tick();
        $credentials = $this->configurator->credentials($tick->connection);
        $protocol = $this->protocols->protocolOf($tick->requireScoringProtocol());

        return $this->rateLimitedCalls->send(
            $calls,
            static fn (array $subset): array => self::receiveAnswers(
                $subset,
                $protocol->scoreMany($credentials, self::requestsOf($subset)),
            ),
            $tick->retryPlan(),
        );
    }

    /**
     * @param ScoringWave         $wave
     * @param ScoringOutcomeModel $outcome
     */
    public function judge(
        BatchWaveInterface $wave,
        int $position,
        BatchCallOutcomeInterface $outcome,
    ): BatchReplyVerdictModel {
        $reply = $outcome->reply();
        $parsed = $this->parser->parse($reply, self::idsOf($wave->batches()[$position]));
        $transcript = self::logged($reply->body);

        return $parsed->usable
            ? BatchReplyVerdictModel::usable($parsed->winners, $transcript)
            : BatchReplyVerdictModel::unusable($transcript);
    }

    /**
     * Books each paid answer the moment it arrives, so a sibling's failure or a deferral still bills what it cost. Only
     * a limited request is re-sent, so no answer is booked twice.
     *
     * @param non-empty-list<BatchCall<ScoringRequestModel>> $calls
     * @param list<ScoringOutcomeModel>                      $outcomes aligned to $calls
     *
     * @return list<ScoringOutcomeModel>
     */
    private static function receiveAnswers(array $calls, array $outcomes): array
    {
        foreach ($outcomes as $index => $outcome) {
            if ($outcome->isFailure()) {
                continue;
            }
            $reply = $outcome->reply();
            $calls[$index]->recordedCall->received($reply->receipt, \strlen($reply->body));
        }

        return $outcomes;
    }

    /**
     * @param non-empty-list<BatchCall<ScoringRequestModel>> $calls
     *
     * @return non-empty-list<ScoringRequestModel>
     */
    private static function requestsOf(array $calls): array
    {
        return array_map(static fn (BatchCall $call): ScoringRequestModel => $call->request, $calls);
    }

    /** A gateway's invalid byte must not reach a utf8mb4 column: MySQL strict mode would fail the tick's write. */
    private static function logged(string $body): string
    {
        return mb_scrub($body, 'UTF-8');
    }

    /** @return list<int> */
    private static function idsOf(WaveBatchModel $batch): array
    {
        return array_map(static fn (ArticleLineModel $line): int => $line->entryId, $batch->linesInSnapshotOrder());
    }
}
