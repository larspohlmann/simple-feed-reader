<?php

declare(strict_types=1);

namespace App\Service\Ai\Llm\Run\Pass;

use App\Service\Ai\Llm\Prompt\Pass\PromptContext;
use App\Service\Ai\Llm\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Pool\Model\CandidatePoolSummaryModel;
use App\Service\Recommendation\Run\Pass\TickContext;

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
