<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Service\Recommendation\Prompt\CandidatePoolSummary;
use App\Service\Recommendation\Prompt\PromptContext;

final readonly class WaveContext
{
    /** @param list<WaveBatch> $batches the plan's next batches, in plan order */
    public function __construct(
        public TickContext $tick,
        public array $batches,
        public ?CandidatePoolSummary $poolSummary,
        public PromptContext $prompt,
    ) {
    }
}
