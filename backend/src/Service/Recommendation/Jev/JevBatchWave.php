<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev;

use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Model\RateLimitedResultModel;
use App\Service\Ai\RateLimitedCalls;
use App\Service\Recommendation\Jev\Factory\SystemOneRequestFactory;
use App\Service\Recommendation\Jev\Model\SystemOneOutcomeModel;
use App\Service\Recommendation\Jev\Model\SystemOneRequestModel;
use App\Service\Recommendation\Jev\Pass\JevWave;
use App\Service\Recommendation\Jev\SystemOneClient\SystemOneClientInterface;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Run\BatchCallOutcome\BatchCallOutcomeInterface;
use App\Service\Recommendation\Run\BatchWave\BatchWaveInterface;
use App\Service\Recommendation\Run\BatchWaveEngine\BatchWaveEngineInterface;
use App\Service\Recommendation\Run\Model\BatchReplyVerdictModel;
use App\Service\Recommendation\Run\Model\CallSlotModel;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\Pass\BatchCall;
use App\Service\Recommendation\Run\RecommendationCallRecorder;

/**
 * One System One request per batch, each a run-log row.
 *
 * @implements BatchWaveEngineInterface<JevWave, SystemOneRequestModel, SystemOneOutcomeModel>
 */
final readonly class JevBatchWave implements BatchWaveEngineInterface
{
    public function __construct(
        private RateLimitedCalls $rateLimitedCalls,
        private SystemOneClientInterface $client,
        private AiProviderConfigurator $configurator,
        private RecommendationCallRecorder $callRecorder,
        private SystemOneRequestFactory $requestFactory,
        private NoulReplyParser $parser,
    ) {
    }

    /**
     * @param JevWave $wave
     *
     * @return BatchCall<SystemOneRequestModel>
     */
    public function open(BatchWaveInterface $wave, int $position): BatchCall
    {
        $tick = $wave->tick();
        $waveBatch = $wave->batches()[$position];
        $request = $this->requestFactory->create($tick->connection, $wave->state, $waveBatch->linesInSnapshotOrder());
        $recordedCall = $this->callRecorder->begin(
            $tick->run,
            CallSlotModel::batch($waveBatch->index + 1),
            $request->toRenderedRequest(),
        );

        return new BatchCall($request, $recordedCall);
    }

    /**
     * @param JevWave                                          $wave
     * @param non-empty-list<BatchCall<SystemOneRequestModel>> $calls
     *
     * @return RateLimitedResultModel<SystemOneOutcomeModel>
     */
    public function sendAll(BatchWaveInterface $wave, array $calls): RateLimitedResultModel
    {
        $tick = $wave->tick();
        $credentials = $this->configurator->credentials($tick->connection);

        return $this->rateLimitedCalls->send(
            $calls,
            fn (array $subset): array => self::receiveAnswers(
                $subset,
                $this->client->evaluateMany($credentials, self::requestsOf($subset)),
            ),
            $tick->retryPlan(),
        );
    }

    /**
     * @param JevWave               $wave
     * @param SystemOneOutcomeModel $outcome
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
     * @param non-empty-list<BatchCall<SystemOneRequestModel>> $calls
     * @param list<SystemOneOutcomeModel>                      $outcomes aligned to $calls
     *
     * @return list<SystemOneOutcomeModel>
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
     * @param non-empty-list<BatchCall<SystemOneRequestModel>> $calls
     *
     * @return non-empty-list<SystemOneRequestModel>
     */
    private static function requestsOf(array $calls): array
    {
        return array_map(static fn (BatchCall $call): SystemOneRequestModel => $call->request, $calls);
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
