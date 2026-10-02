<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

use App\Enum\RecommendationEngineKind;

/** A snapshotted run's frozen plan: which engine runs it, over how many batches. */
final readonly class RunPlanModel
{
    public function __construct(
        public RecommendationEngineKind $engineKind,
        public int $batchCount,
    ) {
    }
}
