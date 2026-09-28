<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Service\Recommendation\Prompt\Model\CandidatePoolSummaryModel;
use App\Service\Recommendation\Prompt\PromptContext;
use App\Service\Recommendation\Run\Model\WaveBatchModel;

final readonly class WaveContext
{
    /** @param list<WaveBatchModel> $batches the plan's next batches, in plan order */
    public function __construct(
        public TickContext $tick,
        public array $batches,
        public ?CandidatePoolSummaryModel $poolSummary,
        public PromptContext $prompt,
    ) {
    }
}
