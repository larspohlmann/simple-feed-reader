<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRunLog;

/** Which run-log row a provider call writes: its phase and, in the batch phase, the 1-based batch number. */
final readonly class CallSlot
{
    private function __construct(
        public string $phase,
        public ?int $batchNumber,
    ) {
    }

    public static function distillation(): self
    {
        return new self(RecommendationRunLog::PHASE_DISTILL, null);
    }

    public static function batch(int $batchNumber): self
    {
        return new self(RecommendationRunLog::PHASE_BATCH, $batchNumber);
    }

    public static function consolidation(): self
    {
        return new self(RecommendationRunLog::PHASE_CONSOLIDATE, null);
    }
}
