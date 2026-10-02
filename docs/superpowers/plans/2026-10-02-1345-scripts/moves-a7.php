<?php

declare(strict_types=1);

// #1345 A7: old FQCN => new FQCN, for the #1344 move scripts (run from backend/).

return [
    'App\Service\Recommendation\Llm\Run\Model\WaveBatchModel' => 'App\Service\Recommendation\Run\Model\WaveBatchModel',
    'App\Service\Recommendation\Llm\Run\Model\BatchWaveResultModel'
        => 'App\Service\Recommendation\Run\Model\BatchWaveResultModel',
    'App\Tests\Service\Recommendation\Llm\Run\Model\WaveBatchModelTest'
        => 'App\Tests\Service\Recommendation\Run\Model\WaveBatchModelTest',
];
