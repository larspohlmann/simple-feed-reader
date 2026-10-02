<?php

declare(strict_types=1);

// #1345 A6: old FQCN => new FQCN, for the #1344 move scripts (run from backend/).

return [
    'App\Service\Recommendation\Llm\Completion\Model\CompletionUsageModel'
        => 'App\Service\Ai\Model\ProviderCallUsageModel',
    'App\Service\Recommendation\Llm\Completion\Model\CompletionStreamProgressModel'
        => 'App\Service\Recommendation\Run\Model\CallProgressModel',
    'App\Service\Recommendation\Llm\Run\Model\CallSlotModel'
        => 'App\Service\Recommendation\Run\Model\CallSlotModel',
    'App\Service\Recommendation\Llm\Run\Factory\RecommendationRunLogFactory'
        => 'App\Service\Recommendation\Run\Factory\RecommendationRunLogFactory',
    'App\Service\Recommendation\Llm\Run\RecommendationCallRecorder'
        => 'App\Service\Recommendation\Run\RecommendationCallRecorder',
    'App\Service\Recommendation\Llm\Run\Pass\RecordedCall'
        => 'App\Service\Recommendation\Run\Pass\RecordedCall',
    'App\Tests\Service\Recommendation\Llm\Completion\Model\CompletionUsageModelTest'
        => 'App\Tests\Service\Ai\Model\ProviderCallUsageModelTest',
    'App\Tests\Service\Recommendation\Llm\Run\Model\CallSlotModelTest'
        => 'App\Tests\Service\Recommendation\Run\Model\CallSlotModelTest',
    'App\Tests\Service\Recommendation\Llm\Run\Factory\RecommendationRunLogFactoryTest'
        => 'App\Tests\Service\Recommendation\Run\Factory\RecommendationRunLogFactoryTest',
    'App\Tests\Service\Recommendation\Llm\Run\RecommendationCallRecorderTest'
        => 'App\Tests\Service\Recommendation\Run\RecommendationCallRecorderTest',
    'App\Tests\Service\Recommendation\Llm\Run\Pass\RecordedCallTest'
        => 'App\Tests\Service\Recommendation\Run\Pass\RecordedCallTest',
];
