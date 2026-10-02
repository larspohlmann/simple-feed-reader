<?php

declare(strict_types=1);

// #1344 Task 3b: old FQCN => new FQCN, for move-classes.php, compare-moves.php and stale-names.php (run from backend/).
// Lars, mid-run (D0): the LLM sub-module is Recommendation\Llm; the OpenAI catalog goes back beside its interface.

return [
    'App\Service\Ai\Llm\Completion\ChatCompletionClient\ChatCompletionClientInterface'
        => 'App\Service\Recommendation\Llm\Completion\ChatCompletionClient\ChatCompletionClientInterface',
    'App\Service\Ai\Llm\Completion\ChatCompletionClient\OpenAiCompatibleChatClient'
        => 'App\Service\Recommendation\Llm\Completion\ChatCompletionClient\OpenAiCompatibleChatClient',
    'App\Service\Ai\Llm\Completion\CompletionBodyDecoder'
        => 'App\Service\Recommendation\Llm\Completion\CompletionBodyDecoder',
    'App\Service\Ai\Llm\Completion\CompletionStreamObserver\CompletionStreamObserverInterface'
        => 'App\Service\Recommendation\Llm\Completion\CompletionStreamObserver\CompletionStreamObserverInterface',
    'App\Service\Ai\Llm\Completion\CompletionStreamObserver\NullCompletionStreamObserver'
        => 'App\Service\Recommendation\Llm\Completion\CompletionStreamObserver\NullCompletionStreamObserver',
    'App\Service\Ai\Llm\Completion\Model\CompletionOutcomeModel'
        => 'App\Service\Recommendation\Llm\Completion\Model\CompletionOutcomeModel',
    'App\Service\Ai\Llm\Completion\Model\CompletionRequestModel'
        => 'App\Service\Recommendation\Llm\Completion\Model\CompletionRequestModel',
    'App\Service\Ai\Llm\Completion\Model\CompletionStreamProgressModel'
        => 'App\Service\Recommendation\Llm\Completion\Model\CompletionStreamProgressModel',
    'App\Service\Ai\Llm\Completion\Model\CompletionUsageModel'
        => 'App\Service\Recommendation\Llm\Completion\Model\CompletionUsageModel',
    'App\Service\Ai\Llm\Completion\Model\JsonSchemaModel'
        => 'App\Service\Recommendation\Llm\Completion\Model\JsonSchemaModel',
    'App\Service\Ai\Llm\Completion\Model\RateLimitedResultModel'
        => 'App\Service\Recommendation\Llm\Completion\Model\RateLimitedResultModel',
    'App\Service\Ai\Llm\Completion\Model\Reasoning' => 'App\Service\Recommendation\Llm\Completion\Model\Reasoning',
    'App\Service\Ai\Llm\Completion\Pass\CompletionCallSlot'
        => 'App\Service\Recommendation\Llm\Completion\Pass\CompletionCallSlot',
    'App\Service\Ai\Llm\Completion\Pass\CompletionStreamReader'
        => 'App\Service\Recommendation\Llm\Completion\Pass\CompletionStreamReader',
    'App\Service\Ai\Llm\Completion\Pass\ConcurrentCompletion'
        => 'App\Service\Recommendation\Llm\Completion\Pass\ConcurrentCompletion',
    'App\Service\Ai\Llm\Completion\RateLimitedCompletion'
        => 'App\Service\Recommendation\Llm\Completion\RateLimitedCompletion',
    'App\Service\Ai\Llm\Completion\Support\CompletionFinishReason'
        => 'App\Service\Recommendation\Llm\Completion\Support\CompletionFinishReason',
    'App\Service\Ai\Llm\LlmRecommendationEngine' => 'App\Service\Recommendation\Llm\LlmRecommendationEngine',
    'App\Service\Ai\Llm\OpenAiCompatibleCatalog' => 'App\Service\Ai\ModelCatalog\OpenAiCompatibleCatalog',
    'App\Service\Ai\Llm\Prompt\Factory\RecommendationCompletionRequestFactory'
        => 'App\Service\Recommendation\Llm\Prompt\Factory\RecommendationCompletionRequestFactory',
    'App\Service\Ai\Llm\Prompt\Model\CallPromptModel' => 'App\Service\Recommendation\Llm\Prompt\Model\CallPromptModel',
    'App\Service\Ai\Llm\Prompt\Model\ConsolidationParseResultModel'
        => 'App\Service\Recommendation\Llm\Prompt\Model\ConsolidationParseResultModel',
    'App\Service\Ai\Llm\Prompt\Model\PickParseResultModel'
        => 'App\Service\Recommendation\Llm\Prompt\Model\PickParseResultModel',
    'App\Service\Ai\Llm\Prompt\Model\ProfileParseResultModel'
        => 'App\Service\Recommendation\Llm\Prompt\Model\ProfileParseResultModel',
    'App\Service\Ai\Llm\Prompt\Model\RecommendationPickModel'
        => 'App\Service\Recommendation\Llm\Prompt\Model\RecommendationPickModel',
    'App\Service\Ai\Llm\Prompt\Model\RecommendationResponseSchema'
        => 'App\Service\Recommendation\Llm\Prompt\Model\RecommendationResponseSchema',
    'App\Service\Ai\Llm\Prompt\ModelReplyJsonDecoder' => 'App\Service\Recommendation\Llm\Prompt\ModelReplyJsonDecoder',
    'App\Service\Ai\Llm\Prompt\Pass\PromptContext' => 'App\Service\Recommendation\Llm\Prompt\Pass\PromptContext',
    'App\Service\Ai\Llm\Prompt\PlausibleDuplicateShare'
        => 'App\Service\Recommendation\Llm\Prompt\PlausibleDuplicateShare',
    'App\Service\Ai\Llm\Prompt\RecommendationAnswerBudget'
        => 'App\Service\Recommendation\Llm\Prompt\RecommendationAnswerBudget',
    'App\Service\Ai\Llm\Prompt\RecommendationConsolidationParser'
        => 'App\Service\Recommendation\Llm\Prompt\RecommendationConsolidationParser',
    'App\Service\Ai\Llm\Prompt\RecommendationPickParser'
        => 'App\Service\Recommendation\Llm\Prompt\RecommendationPickParser',
    'App\Service\Ai\Llm\Prompt\RecommendationPickSalvager'
        => 'App\Service\Recommendation\Llm\Prompt\RecommendationPickSalvager',
    'App\Service\Ai\Llm\Prompt\RecommendationProfileParser'
        => 'App\Service\Recommendation\Llm\Prompt\RecommendationProfileParser',
    'App\Service\Ai\Llm\Prompt\RecommendationPromptBuilder'
        => 'App\Service\Recommendation\Llm\Prompt\RecommendationPromptBuilder',
    'App\Service\Ai\Llm\Prompt\Support\RecommendationPromptText'
        => 'App\Service\Recommendation\Llm\Prompt\Support\RecommendationPromptText',
    'App\Service\Ai\Llm\Run\Factory\RecommendationRunLogFactory'
        => 'App\Service\Recommendation\Llm\Run\Factory\RecommendationRunLogFactory',
    'App\Service\Ai\Llm\Run\InvalidReplyRetry' => 'App\Service\Recommendation\Llm\Run\InvalidReplyRetry',
    'App\Service\Ai\Llm\Run\Model\BatchWaveResultModel'
        => 'App\Service\Recommendation\Llm\Run\Model\BatchWaveResultModel',
    'App\Service\Ai\Llm\Run\Model\CallSlotModel' => 'App\Service\Recommendation\Llm\Run\Model\CallSlotModel',
    'App\Service\Ai\Llm\Run\Model\ConsolidationOutcomeModel'
        => 'App\Service\Recommendation\Llm\Run\Model\ConsolidationOutcomeModel',
    'App\Service\Ai\Llm\Run\Model\ProfileDistillationOutcomeModel'
        => 'App\Service\Recommendation\Llm\Run\Model\ProfileDistillationOutcomeModel',
    'App\Service\Ai\Llm\Run\Model\WaveBatchModel' => 'App\Service\Recommendation\Llm\Run\Model\WaveBatchModel',
    'App\Service\Ai\Llm\Run\Pass\RecordedCall' => 'App\Service\Recommendation\Llm\Run\Pass\RecordedCall',
    'App\Service\Ai\Llm\Run\Pass\WaveContext' => 'App\Service\Recommendation\Llm\Run\Pass\WaveContext',
    'App\Service\Ai\Llm\Run\ProviderPhase\BatchPhase' => 'App\Service\Recommendation\Llm\Run\ProviderPhase\BatchPhase',
    'App\Service\Ai\Llm\Run\ProviderPhase\ConsolidationPhase'
        => 'App\Service\Recommendation\Llm\Run\ProviderPhase\ConsolidationPhase',
    'App\Service\Ai\Llm\Run\ProviderPhase\DistillationPhase'
        => 'App\Service\Recommendation\Llm\Run\ProviderPhase\DistillationPhase',
    'App\Service\Ai\Llm\Run\ProviderPhase\ProviderPhaseInterface'
        => 'App\Service\Recommendation\Llm\Run\ProviderPhase\ProviderPhaseInterface',
    'App\Service\Ai\Llm\Run\RecommendationBatchWave' => 'App\Service\Recommendation\Llm\Run\RecommendationBatchWave',
    'App\Service\Ai\Llm\Run\RecommendationCallRecorder'
        => 'App\Service\Recommendation\Llm\Run\RecommendationCallRecorder',
    'App\Service\Ai\Llm\Run\RecommendationConsolidationResolver'
        => 'App\Service\Recommendation\Llm\Run\RecommendationConsolidationResolver',
    'App\Service\Ai\Llm\Run\RecommendationProfileDistiller'
        => 'App\Service\Recommendation\Llm\Run\RecommendationProfileDistiller',
    'App\Service\Ai\Llm\Run\RecommendationProviderCall'
        => 'App\Service\Recommendation\Llm\Run\RecommendationProviderCall',
    'App\Service\Ai\Llm\Run\WaveContextLoader' => 'App\Service\Recommendation\Llm\Run\WaveContextLoader',
    'App\Tests\Service\Ai\Llm\Completion\ChatCompletionClient\OpenAiCompatibleChatClientTest'
        => 'App\Tests\Service\Recommendation\Llm\Completion\ChatCompletionClient\OpenAiCompatibleChatClientTest',
    'App\Tests\Service\Ai\Llm\Completion\CompletionBodyDecoderTest'
        => 'App\Tests\Service\Recommendation\Llm\Completion\CompletionBodyDecoderTest',
    'App\Tests\Service\Ai\Llm\Completion\Model\CompletionOutcomeModelTest'
        => 'App\Tests\Service\Recommendation\Llm\Completion\Model\CompletionOutcomeModelTest',
    'App\Tests\Service\Ai\Llm\Completion\Model\CompletionUsageModelTest'
        => 'App\Tests\Service\Recommendation\Llm\Completion\Model\CompletionUsageModelTest',
    'App\Tests\Service\Ai\Llm\Completion\Model\ReasoningTest'
        => 'App\Tests\Service\Recommendation\Llm\Completion\Model\ReasoningTest',
    'App\Tests\Service\Ai\Llm\Completion\Pass\CompletionStreamReaderTest'
        => 'App\Tests\Service\Recommendation\Llm\Completion\Pass\CompletionStreamReaderTest',
    'App\Tests\Service\Ai\Llm\Completion\RateLimitedCompletionTest'
        => 'App\Tests\Service\Recommendation\Llm\Completion\RateLimitedCompletionTest',
    'App\Tests\Service\Ai\Llm\Completion\Support\CompletionFinishReasonTest'
        => 'App\Tests\Service\Recommendation\Llm\Completion\Support\CompletionFinishReasonTest',
    'App\Tests\Service\Ai\Llm\LlmRecommendationEngineTest'
        => 'App\Tests\Service\Recommendation\Llm\LlmRecommendationEngineTest',
    'App\Tests\Service\Ai\Llm\OpenAiCompatibleCatalogTest'
        => 'App\Tests\Service\Ai\ModelCatalog\OpenAiCompatibleCatalogTest',
    'App\Tests\Service\Ai\Llm\Prompt\Factory\RecommendationCompletionRequestFactoryTest'
        => 'App\Tests\Service\Recommendation\Llm\Prompt\Factory\RecommendationCompletionRequestFactoryTest',
    'App\Tests\Service\Ai\Llm\Prompt\Model\RecommendationResponseSchemaTest'
        => 'App\Tests\Service\Recommendation\Llm\Prompt\Model\RecommendationResponseSchemaTest',
    'App\Tests\Service\Ai\Llm\Prompt\ModelReplyJsonDecoderTest'
        => 'App\Tests\Service\Recommendation\Llm\Prompt\ModelReplyJsonDecoderTest',
    'App\Tests\Service\Ai\Llm\Prompt\RecommendationAnswerBudgetTest'
        => 'App\Tests\Service\Recommendation\Llm\Prompt\RecommendationAnswerBudgetTest',
    'App\Tests\Service\Ai\Llm\Prompt\RecommendationConsolidationParserTest'
        => 'App\Tests\Service\Recommendation\Llm\Prompt\RecommendationConsolidationParserTest',
    'App\Tests\Service\Ai\Llm\Prompt\RecommendationPickParserTest'
        => 'App\Tests\Service\Recommendation\Llm\Prompt\RecommendationPickParserTest',
    'App\Tests\Service\Ai\Llm\Prompt\RecommendationProfileParserTest'
        => 'App\Tests\Service\Recommendation\Llm\Prompt\RecommendationProfileParserTest',
    'App\Tests\Service\Ai\Llm\Prompt\RecommendationPromptBuilderTest'
        => 'App\Tests\Service\Recommendation\Llm\Prompt\RecommendationPromptBuilderTest',
    'App\Tests\Service\Ai\Llm\Run\Factory\RecommendationRunLogFactoryTest'
        => 'App\Tests\Service\Recommendation\Llm\Run\Factory\RecommendationRunLogFactoryTest',
    'App\Tests\Service\Ai\Llm\Run\Model\CallSlotModelTest'
        => 'App\Tests\Service\Recommendation\Llm\Run\Model\CallSlotModelTest',
    'App\Tests\Service\Ai\Llm\Run\Model\ConsolidationOutcomeModelTest'
        => 'App\Tests\Service\Recommendation\Llm\Run\Model\ConsolidationOutcomeModelTest',
    'App\Tests\Service\Ai\Llm\Run\Model\ProfileDistillationOutcomeModelTest'
        => 'App\Tests\Service\Recommendation\Llm\Run\Model\ProfileDistillationOutcomeModelTest',
    'App\Tests\Service\Ai\Llm\Run\Model\WaveBatchModelTest'
        => 'App\Tests\Service\Recommendation\Llm\Run\Model\WaveBatchModelTest',
    'App\Tests\Service\Ai\Llm\Run\Pass\RecordedCallTest'
        => 'App\Tests\Service\Recommendation\Llm\Run\Pass\RecordedCallTest',
    'App\Tests\Service\Ai\Llm\Run\RecommendationCallRecorderTest'
        => 'App\Tests\Service\Recommendation\Llm\Run\RecommendationCallRecorderTest',
    'App\Tests\Service\Ai\Llm\Run\RecommendationConsolidationResolverTest'
        => 'App\Tests\Service\Recommendation\Llm\Run\RecommendationConsolidationResolverTest',
    'App\Tests\Service\Ai\Llm\Run\RecommendationProfileDistillerTest'
        => 'App\Tests\Service\Recommendation\Llm\Run\RecommendationProfileDistillerTest',
    'App\Tests\Service\Ai\Llm\Run\WaveContextLoaderTest'
        => 'App\Tests\Service\Recommendation\Llm\Run\WaveContextLoaderTest',
];
