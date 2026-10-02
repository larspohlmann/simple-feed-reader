<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

use App\Enum\RecommendationEngineKind;

final readonly class RunPlanModel
{
    public function __construct(
        public RecommendationEngineKind $engineKind,
        public int $batchCount,
    ) {
    }
}
