<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Run\Pass;

use App\Service\Recommendation\Llm\Prompt\Pass\PromptContext;
use App\Service\Recommendation\Pool\Model\CandidatePoolSummaryModel;
use App\Service\Recommendation\Run\BatchWave\BatchWaveInterface;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\Pass\TickContext;

final class WaveContext implements BatchWaveInterface
{
    /** @var array<int, string> each position's own last invalid reply, which its retry quotes back */
    private array $invalidReplies = [];

    /** @param list<WaveBatchModel> $batches the plan's next batches, in plan order */
    public function __construct(
        private readonly TickContext $tick,
        private readonly array $batches,
        public readonly ?CandidatePoolSummaryModel $poolSummary,
        public readonly PromptContext $prompt,
    ) {
    }

    public function tick(): TickContext
    {
        return $this->tick;
    }

    public function batches(): array
    {
        return $this->batches;
    }

    public function rememberInvalidReply(int $position, string $reply): void
    {
        $this->invalidReplies[$position] = $reply;
    }

    public function lastInvalidReply(int $position): ?string
    {
        return $this->invalidReplies[$position] ?? null;
    }
}
