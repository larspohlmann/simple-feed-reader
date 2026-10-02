<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Run;

use App\Service\Ai\Factory\ProviderConnectionFactory;
use App\Service\Ai\Model\RateLimitedResultModel;
use App\Service\Recommendation\Llm\Completion\Model\CompletionOutcomeModel;
use App\Service\Recommendation\Llm\Completion\Model\CompletionRequestModel;
use App\Service\Recommendation\Llm\Completion\Pass\ConcurrentCompletion;
use App\Service\Recommendation\Llm\Completion\RateLimitedCompletion;
use App\Service\Recommendation\Llm\Prompt\Factory\RecommendationCompletionRequestFactory;
use App\Service\Recommendation\Llm\Prompt\Model\CallPromptModel;
use App\Service\Recommendation\Llm\Prompt\Model\RecommendationPickModel;
use App\Service\Recommendation\Llm\Prompt\Model\RecommendationResponseSchema;
use App\Service\Recommendation\Llm\Prompt\RecommendationPickParser;
use App\Service\Recommendation\Llm\Prompt\RecommendationPromptBuilder;
use App\Service\Recommendation\Llm\Prompt\Support\RecommendationPromptText;
use App\Service\Recommendation\Llm\Run\Pass\RecordedCallObserver;
use App\Service\Recommendation\Llm\Run\Pass\WaveContext;
use App\Service\Recommendation\Run\BatchCallOutcome\BatchCallOutcomeInterface;
use App\Service\Recommendation\Run\BatchWave\BatchWaveInterface;
use App\Service\Recommendation\Run\BatchWaveEngine\BatchWaveEngineInterface;
use App\Service\Recommendation\Run\Model\BatchReplyVerdictModel;
use App\Service\Recommendation\Run\Model\CallSlotModel;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\Pass\BatchCall;

/**
 * The batch phase's streamed completions: a batch's retry quotes its own last invalid reply back.
 *
 * @implements BatchWaveEngineInterface<WaveContext, CompletionRequestModel, CompletionOutcomeModel>
 */
final readonly class RecommendationBatchWave implements BatchWaveEngineInterface
{
    public function __construct(
        private RateLimitedCompletion $completion,
        private ProviderConnectionFactory $connectionFactory,
        private CompletionCallRecorder $callRecorder,
        private RecommendationPromptBuilder $promptBuilder,
        private RecommendationPickParser $parser,
        private RecommendationCompletionRequestFactory $requestFactory,
    ) {
    }

    /**
     * @param WaveContext $wave
     *
     * @return BatchCall<CompletionRequestModel>
     */
    public function open(BatchWaveInterface $wave, int $position): BatchCall
    {
        $tick = $wave->tick();
        $waveBatch = $wave->batches()[$position];
        $messages = $this->batchMessages($wave, $waveBatch, $wave->lastInvalidReply($position));
        $request = $this->requestFactory->create(
            $tick->connection,
            new CallPromptModel($messages, \count($waveBatch->validIds()), RecommendationResponseSchema::BatchScore),
        );
        $slot = CallSlotModel::batch($waveBatch->index + 1);

        return new BatchCall($request, $this->callRecorder->begin($tick->run, $slot, $request));
    }

    /**
     * @param WaveContext                                       $wave
     * @param non-empty-list<BatchCall<CompletionRequestModel>> $calls
     *
     * @return RateLimitedResultModel<CompletionOutcomeModel>
     */
    public function sendAll(BatchWaveInterface $wave, array $calls): RateLimitedResultModel
    {
        $tick = $wave->tick();

        return $this->completion->completeMany(
            $this->connectionFactory->forSettings($tick->connection),
            array_map(
                static fn (BatchCall $call): ConcurrentCompletion => new ConcurrentCompletion(
                    $call->request,
                    new RecordedCallObserver($call->recordedCall),
                ),
                $calls,
            ),
            $tick->retryPlan(),
        );
    }

    /**
     * @param WaveContext            $wave
     * @param CompletionOutcomeModel $outcome
     */
    public function judge(
        BatchWaveInterface $wave,
        int $position,
        BatchCallOutcomeInterface $outcome,
    ): BatchReplyVerdictModel {
        // content() covers a spoiled reply too: the partial answer the parser judges and the retry quotes back.
        $content = $outcome->content();
        $result = $this->parser->parse($content, $wave->batches()[$position]->validIds());
        if ($result->usable) {
            return BatchReplyVerdictModel::usable(self::asWinners($result->picks), $content);
        }
        $wave->rememberInvalidReply($position, $content);

        return BatchReplyVerdictModel::unusable($content);
    }

    /** @return list<array{role: string, content: string}> */
    private function batchMessages(WaveContext $wave, WaveBatchModel $waveBatch, ?string $lastInvalidReply): array
    {
        $messages = $this->promptBuilder->batchMessages(
            $wave->prompt,
            $waveBatch->linesInSnapshotOrder(),
            $wave->poolSummary,
        );

        return $this->promptBuilder->messagesWithCorrectiveTail(
            $messages,
            $lastInvalidReply,
            RecommendationPromptText::CORRECTIVE,
        );
    }

    /**
     * @param list<RecommendationPickModel> $picks
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    private static function asWinners(array $picks): array
    {
        return array_map(
            static fn (RecommendationPickModel $pick): array => [
                'id' => $pick->entryId,
                'score' => $pick->score,
                'reason' => $pick->reason,
            ],
            $picks,
        );
    }
}
