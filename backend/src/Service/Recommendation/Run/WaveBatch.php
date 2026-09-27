<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Service\Recommendation\Prompt\PromptLine;

/**
 * One batch of the frozen plan in a wave (#344): its plan position, its snapshot-order ids, and the prompt lines
 * those ids still resolve to. A batch pruned to nothing resolves to no winners without a provider call.
 */
final readonly class WaveBatch
{
    /**
     * @param list<int>              $ids       the batch's entry ids, in snapshot order
     * @param array<int, PromptLine> $linesById entries pruned since the snapshot are absent
     */
    public function __construct(
        public int $index,
        public array $ids,
        public array $linesById,
    ) {
    }

    /**
     * The ids whose entries still exist, so the reply must cover exactly these.
     *
     * @return list<int>
     */
    public function validIds(): array
    {
        return array_keys($this->linesById);
    }

    public function isFullyPruned(): bool
    {
        return [] === $this->linesById;
    }
}
