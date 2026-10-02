<?php

declare(strict_types=1);

// #1344 Task 3: old FQCN => new FQCN, for move-classes.php, compare-moves.php and stale-names.php (run from backend/).

return [
    // The chat-completion transport: module Ai\Llm.
    'App\Service\Ai\Completion\ChatCompletionClient\ChatCompletionClientInterface'
        => 'App\Service\Ai\Llm\Completion\ChatCompletionClient\ChatCompletionClientInterface',
    'App\Service\Ai\Completion\ChatCompletionClient\OpenAiCompatibleChatClient'
        => 'App\Service\Ai\Llm\Completion\ChatCompletionClient\OpenAiCompatibleChatClient',
    'App\Service\Ai\Completion\CompletionBodyDecoder' => 'App\Service\Ai\Llm\Completion\CompletionBodyDecoder',
    'App\Service\Ai\Completion\CompletionStreamObserver\CompletionStreamObserverInterface'
        => 'App\Service\Ai\Llm\Completion\CompletionStreamObserver\CompletionStreamObserverInterface',
    'App\Service\Ai\Completion\CompletionStreamObserver\NullCompletionStreamObserver'
        => 'App\Service\Ai\Llm\Completion\CompletionStreamObserver\NullCompletionStreamObserver',
    'App\Service\Ai\Completion\Model\CompletionOutcomeModel' => 'App\Service\Ai\Llm\Completion\Model\CompletionOutcomeModel',
    'App\Service\Ai\Completion\Model\CompletionRequestModel' => 'App\Service\Ai\Llm\Completion\Model\CompletionRequestModel',
    'App\Service\Ai\Completion\Model\CompletionStreamProgressModel'
        => 'App\Service\Ai\Llm\Completion\Model\CompletionStreamProgressModel',
    'App\Service\Ai\Completion\Model\CompletionUsageModel' => 'App\Service\Ai\Llm\Completion\Model\CompletionUsageModel',
    'App\Service\Ai\Completion\Model\JsonSchemaModel' => 'App\Service\Ai\Llm\Completion\Model\JsonSchemaModel',
    'App\Service\Ai\Completion\Model\RateLimitedResultModel' => 'App\Service\Ai\Llm\Completion\Model\RateLimitedResultModel',
    'App\Service\Ai\Completion\Model\Reasoning' => 'App\Service\Ai\Llm\Completion\Model\Reasoning',
    'App\Service\Ai\Completion\Pass\CompletionCallSlot' => 'App\Service\Ai\Llm\Completion\Pass\CompletionCallSlot',
    'App\Service\Ai\Completion\Pass\CompletionStreamReader' => 'App\Service\Ai\Llm\Completion\Pass\CompletionStreamReader',
    'App\Service\Ai\Completion\Pass\ConcurrentCompletion' => 'App\Service\Ai\Llm\Completion\Pass\ConcurrentCompletion',
    'App\Service\Ai\Completion\RateLimitedCompletion' => 'App\Service\Ai\Llm\Completion\RateLimitedCompletion',
    'App\Service\Ai\Completion\Support\CompletionFinishReason' => 'App\Service\Ai\Llm\Completion\Support\CompletionFinishReason',
    'App\Service\Ai\ModelCatalog\OpenAiCompatibleCatalog' => 'App\Service\Ai\Llm\OpenAiCompatibleCatalog',

    // Engine-neutral: the retry plan joins the Ai models; the heartbeat becomes Recommendation's.
    'App\Service\Ai\Completion\Model\RetryPlanModel' => 'App\Service\Ai\Model\RetryPlanModel',
    'App\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface'
        => 'App\Service\Recommendation\Run\ProviderCallHeartbeat\ProviderCallHeartbeatInterface',
    'App\Service\Ai\Completion\CompletionStreamHeartbeat\CompositeCompletionStreamHeartbeat'
        => 'App\Service\Recommendation\Run\ProviderCallHeartbeat\CompositeProviderCallHeartbeat',
    'App\Service\Recommendation\Run\TickLockKeepalive'
        => 'App\Service\Recommendation\Run\ProviderCallHeartbeat\TickLockKeepalive',
    'App\Service\Recommendation\Run\SweepStreamHeartbeat'
        => 'App\Service\Recommendation\Run\ProviderCallHeartbeat\SweepStreamHeartbeat',

    // The candidate pool and the reading history: engine-neutral, Recommendation\Pool.
    'App\Service\Recommendation\Prompt\RecommendationCandidateLoader'
        => 'App\Service\Recommendation\Pool\RecommendationCandidateLoader',
    'App\Service\Recommendation\Prompt\RecommendationHistoryLoader'
        => 'App\Service\Recommendation\Pool\RecommendationHistoryLoader',
    'App\Service\Recommendation\Prompt\Model\PromptLineModel' => 'App\Service\Recommendation\Pool\Model\ArticleLineModel',
    'App\Service\Recommendation\Prompt\Model\CandidatePoolRequestModel'
        => 'App\Service\Recommendation\Pool\Model\CandidatePoolRequestModel',
    'App\Service\Recommendation\Prompt\Model\CandidatePoolSummaryModel'
        => 'App\Service\Recommendation\Pool\Model\CandidatePoolSummaryModel',
    'App\Service\Recommendation\Prompt\Model\RecommendationHistoryModel'
        => 'App\Service\Recommendation\Pool\Model\RecommendationHistoryModel',

    // Prompts, budgets and reply parsing: Ai\Llm\Prompt.
    'App\Service\Recommendation\Prompt\Factory\RecommendationCompletionRequestFactory'
        => 'App\Service\Ai\Llm\Prompt\Factory\RecommendationCompletionRequestFactory',
    'App\Service\Recommendation\Prompt\Model\CallPromptModel' => 'App\Service\Ai\Llm\Prompt\Model\CallPromptModel',
    'App\Service\Recommendation\Prompt\Model\ConsolidationParseResultModel'
        => 'App\Service\Ai\Llm\Prompt\Model\ConsolidationParseResultModel',
    'App\Service\Recommendation\Prompt\Model\PickParseResultModel' => 'App\Service\Ai\Llm\Prompt\Model\PickParseResultModel',
    'App\Service\Recommendation\Prompt\Model\ProfileParseResultModel'
        => 'App\Service\Ai\Llm\Prompt\Model\ProfileParseResultModel',
    'App\Service\Recommendation\Prompt\Model\RecommendationPickModel'
        => 'App\Service\Ai\Llm\Prompt\Model\RecommendationPickModel',
    'App\Service\Recommendation\Prompt\Model\RecommendationResponseSchema'
        => 'App\Service\Ai\Llm\Prompt\Model\RecommendationResponseSchema',
    'App\Service\Recommendation\Prompt\ModelReplyJsonDecoder' => 'App\Service\Ai\Llm\Prompt\ModelReplyJsonDecoder',
    'App\Service\Recommendation\Prompt\Pass\PromptContext' => 'App\Service\Ai\Llm\Prompt\Pass\PromptContext',
    'App\Service\Recommendation\Prompt\PlausibleDuplicateShare' => 'App\Service\Ai\Llm\Prompt\PlausibleDuplicateShare',
    'App\Service\Recommendation\Prompt\RecommendationAnswerBudget' => 'App\Service\Ai\Llm\Prompt\RecommendationAnswerBudget',
    'App\Service\Recommendation\Prompt\RecommendationConsolidationParser'
        => 'App\Service\Ai\Llm\Prompt\RecommendationConsolidationParser',
    'App\Service\Recommendation\Prompt\RecommendationPickParser' => 'App\Service\Ai\Llm\Prompt\RecommendationPickParser',
    'App\Service\Recommendation\Prompt\RecommendationPickSalvager' => 'App\Service\Ai\Llm\Prompt\RecommendationPickSalvager',
    'App\Service\Recommendation\Prompt\RecommendationProfileParser'
        => 'App\Service\Ai\Llm\Prompt\RecommendationProfileParser',
    'App\Service\Recommendation\Prompt\RecommendationPromptBuilder'
        => 'App\Service\Ai\Llm\Prompt\RecommendationPromptBuilder',
    'App\Service\Recommendation\Prompt\Support\RecommendationPromptText'
        => 'App\Service\Ai\Llm\Prompt\Support\RecommendationPromptText',

    // The distill / batch / consolidate run: Ai\Llm\Run.
    'App\Service\Recommendation\Run\ProviderPhase\BatchPhase' => 'App\Service\Ai\Llm\Run\ProviderPhase\BatchPhase',
    'App\Service\Recommendation\Run\ProviderPhase\ConsolidationPhase'
        => 'App\Service\Ai\Llm\Run\ProviderPhase\ConsolidationPhase',
    'App\Service\Recommendation\Run\ProviderPhase\DistillationPhase'
        => 'App\Service\Ai\Llm\Run\ProviderPhase\DistillationPhase',
    'App\Service\Recommendation\Run\ProviderPhase\ProviderPhaseInterface'
        => 'App\Service\Ai\Llm\Run\ProviderPhase\ProviderPhaseInterface',
    'App\Service\Recommendation\Run\RecommendationBatchWave' => 'App\Service\Ai\Llm\Run\RecommendationBatchWave',
    'App\Service\Recommendation\Run\RecommendationCallRecorder' => 'App\Service\Ai\Llm\Run\RecommendationCallRecorder',
    'App\Service\Recommendation\Run\RecommendationConsolidationResolver'
        => 'App\Service\Ai\Llm\Run\RecommendationConsolidationResolver',
    'App\Service\Recommendation\Run\RecommendationProfileDistiller'
        => 'App\Service\Ai\Llm\Run\RecommendationProfileDistiller',
    'App\Service\Recommendation\Run\RecommendationProviderCall' => 'App\Service\Ai\Llm\Run\RecommendationProviderCall',
    'App\Service\Recommendation\Run\InvalidReplyRetry' => 'App\Service\Ai\Llm\Run\InvalidReplyRetry',
    'App\Service\Recommendation\Run\WaveContextLoader' => 'App\Service\Ai\Llm\Run\WaveContextLoader',
    'App\Service\Recommendation\Run\Pass\RecordedCall' => 'App\Service\Ai\Llm\Run\Pass\RecordedCall',
    'App\Service\Recommendation\Run\Pass\WaveContext' => 'App\Service\Ai\Llm\Run\Pass\WaveContext',
    'App\Service\Recommendation\Run\Model\BatchWaveResultModel' => 'App\Service\Ai\Llm\Run\Model\BatchWaveResultModel',
    'App\Service\Recommendation\Run\Model\CallSlotModel' => 'App\Service\Ai\Llm\Run\Model\CallSlotModel',
    'App\Service\Recommendation\Run\Model\ConsolidationOutcomeModel'
        => 'App\Service\Ai\Llm\Run\Model\ConsolidationOutcomeModel',
    'App\Service\Recommendation\Run\Model\ProfileDistillationOutcomeModel'
        => 'App\Service\Ai\Llm\Run\Model\ProfileDistillationOutcomeModel',
    'App\Service\Recommendation\Run\Model\WaveBatchModel' => 'App\Service\Ai\Llm\Run\Model\WaveBatchModel',
    'App\Service\Recommendation\Run\Factory\RecommendationRunLogFactory'
        => 'App\Service\Ai\Llm\Run\Factory\RecommendationRunLogFactory',

    // The engine itself.
    'App\Service\Recommendation\Engine\RecommendationEngine\LlmRecommendationEngine'
        => 'App\Service\Ai\Llm\LlmRecommendationEngine',

    // Tests follow their classes.
    'App\Tests\Service\Ai\Completion\ChatCompletionClient\OpenAiCompatibleChatClientTest'
        => 'App\Tests\Service\Ai\Llm\Completion\ChatCompletionClient\OpenAiCompatibleChatClientTest',
    'App\Tests\Service\Ai\Completion\CompletionBodyDecoderTest'
        => 'App\Tests\Service\Ai\Llm\Completion\CompletionBodyDecoderTest',
    'App\Tests\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatWiringTest'
        => 'App\Tests\Service\Recommendation\Run\ProviderCallHeartbeat\ProviderCallHeartbeatWiringTest',
    'App\Tests\Service\Ai\Completion\CompletionStreamHeartbeat\CompositeCompletionStreamHeartbeatTest'
        => 'App\Tests\Service\Recommendation\Run\ProviderCallHeartbeat\CompositeProviderCallHeartbeatTest',
    'App\Tests\Service\Ai\Completion\Model\CompletionOutcomeModelTest'
        => 'App\Tests\Service\Ai\Llm\Completion\Model\CompletionOutcomeModelTest',
    'App\Tests\Service\Ai\Completion\Model\CompletionUsageModelTest'
        => 'App\Tests\Service\Ai\Llm\Completion\Model\CompletionUsageModelTest',
    'App\Tests\Service\Ai\Completion\Model\ReasoningTest' => 'App\Tests\Service\Ai\Llm\Completion\Model\ReasoningTest',
    'App\Tests\Service\Ai\Completion\Model\RetryPlanModelTest' => 'App\Tests\Service\Ai\Model\RetryPlanModelTest',
    'App\Tests\Service\Ai\Completion\Pass\CompletionStreamReaderTest'
        => 'App\Tests\Service\Ai\Llm\Completion\Pass\CompletionStreamReaderTest',
    'App\Tests\Service\Ai\Completion\RateLimitedCompletionTest'
        => 'App\Tests\Service\Ai\Llm\Completion\RateLimitedCompletionTest',
    'App\Tests\Service\Ai\Completion\Support\CompletionFinishReasonTest'
        => 'App\Tests\Service\Ai\Llm\Completion\Support\CompletionFinishReasonTest',
    'App\Tests\Service\Ai\ModelCatalog\OpenAiCompatibleCatalogTest'
        => 'App\Tests\Service\Ai\Llm\OpenAiCompatibleCatalogTest',
    'App\Tests\Service\Recommendation\Prompt\Factory\RecommendationCompletionRequestFactoryTest'
        => 'App\Tests\Service\Ai\Llm\Prompt\Factory\RecommendationCompletionRequestFactoryTest',
    'App\Tests\Service\Recommendation\Prompt\Model\RecommendationResponseSchemaTest'
        => 'App\Tests\Service\Ai\Llm\Prompt\Model\RecommendationResponseSchemaTest',
    'App\Tests\Service\Recommendation\Prompt\ModelReplyJsonDecoderTest'
        => 'App\Tests\Service\Ai\Llm\Prompt\ModelReplyJsonDecoderTest',
    'App\Tests\Service\Recommendation\Prompt\RecommendationAnswerBudgetTest'
        => 'App\Tests\Service\Ai\Llm\Prompt\RecommendationAnswerBudgetTest',
    'App\Tests\Service\Recommendation\Prompt\RecommendationCandidateLoaderTest'
        => 'App\Tests\Service\Recommendation\Pool\RecommendationCandidateLoaderTest',
    'App\Tests\Service\Recommendation\Prompt\RecommendationConsolidationParserTest'
        => 'App\Tests\Service\Ai\Llm\Prompt\RecommendationConsolidationParserTest',
    'App\Tests\Service\Recommendation\Prompt\RecommendationHistoryLoaderTest'
        => 'App\Tests\Service\Recommendation\Pool\RecommendationHistoryLoaderTest',
    'App\Tests\Service\Recommendation\Prompt\RecommendationPickParserTest'
        => 'App\Tests\Service\Ai\Llm\Prompt\RecommendationPickParserTest',
    'App\Tests\Service\Recommendation\Prompt\RecommendationProfileParserTest'
        => 'App\Tests\Service\Ai\Llm\Prompt\RecommendationProfileParserTest',
    'App\Tests\Service\Recommendation\Prompt\RecommendationPromptBuilderTest'
        => 'App\Tests\Service\Ai\Llm\Prompt\RecommendationPromptBuilderTest',
    'App\Tests\Service\Recommendation\Run\Factory\RecommendationRunLogFactoryTest'
        => 'App\Tests\Service\Ai\Llm\Run\Factory\RecommendationRunLogFactoryTest',
    'App\Tests\Service\Recommendation\Run\Model\CallSlotModelTest' => 'App\Tests\Service\Ai\Llm\Run\Model\CallSlotModelTest',
    'App\Tests\Service\Recommendation\Run\Model\ConsolidationOutcomeModelTest'
        => 'App\Tests\Service\Ai\Llm\Run\Model\ConsolidationOutcomeModelTest',
    'App\Tests\Service\Recommendation\Run\Model\ProfileDistillationOutcomeModelTest'
        => 'App\Tests\Service\Ai\Llm\Run\Model\ProfileDistillationOutcomeModelTest',
    'App\Tests\Service\Recommendation\Run\Model\WaveBatchModelTest'
        => 'App\Tests\Service\Ai\Llm\Run\Model\WaveBatchModelTest',
    'App\Tests\Service\Recommendation\Run\Pass\RecordedCallTest' => 'App\Tests\Service\Ai\Llm\Run\Pass\RecordedCallTest',
    'App\Tests\Service\Recommendation\Run\RecommendationCallRecorderTest'
        => 'App\Tests\Service\Ai\Llm\Run\RecommendationCallRecorderTest',
    'App\Tests\Service\Recommendation\Run\RecommendationConsolidationResolverTest'
        => 'App\Tests\Service\Ai\Llm\Run\RecommendationConsolidationResolverTest',
    'App\Tests\Service\Recommendation\Run\RecommendationProfileDistillerTest'
        => 'App\Tests\Service\Ai\Llm\Run\RecommendationProfileDistillerTest',
    'App\Tests\Service\Recommendation\Run\WaveContextLoaderTest' => 'App\Tests\Service\Ai\Llm\Run\WaveContextLoaderTest',
    'App\Tests\Service\Recommendation\Run\SweepStreamHeartbeatTest'
        => 'App\Tests\Service\Recommendation\Run\ProviderCallHeartbeat\SweepStreamHeartbeatTest',
    'App\Tests\Service\Recommendation\Run\TickLockKeepaliveTest'
        => 'App\Tests\Service\Recommendation\Run\ProviderCallHeartbeat\TickLockKeepaliveTest',
    'App\Tests\Service\Recommendation\Engine\RecommendationEngine\LlmRecommendationEngineTest'
        => 'App\Tests\Service\Ai\Llm\LlmRecommendationEngineTest',
    'App\Tests\Support\CountingCompletionStreamHeartbeat' => 'App\Tests\Support\CountingProviderCallHeartbeat',
    'App\Tests\Support\NullCompletionStreamHeartbeat' => 'App\Tests\Support\NullProviderCallHeartbeat',
];
