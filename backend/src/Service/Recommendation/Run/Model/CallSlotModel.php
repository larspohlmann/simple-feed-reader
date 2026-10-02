<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

use App\Enum\CallPhase;

/** Which run-log row a provider call writes: its phase and, in the batch phase, the 1-based batch number. */
final readonly class CallSlotModel
{
    private function __construct(
        public CallPhase $phase,
        public ?int $batchNumber,
    ) {
    }

    public static function distillation(): self
    {
        return new self(CallPhase::Distill, null);
    }

    public static function batch(int $batchNumber): self
    {
        return new self(CallPhase::Batch, $batchNumber);
    }

    public static function consolidation(): self
    {
        return new self(CallPhase::Consolidate, null);
    }
}
