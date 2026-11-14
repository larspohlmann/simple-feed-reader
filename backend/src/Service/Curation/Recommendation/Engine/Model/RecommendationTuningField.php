<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine\Model;

enum RecommendationTuningField: string
{
    case ContextWindow = 'contextWindow';
    case BatchSize = 'batchSize';
    case SuppressReasoning = 'suppressReasoning';
    case SlowModel = 'slowModel';
    case MaxBatchSize = 'maxBatchSize';
    case BatchConcurrency = 'batchConcurrency';
}
