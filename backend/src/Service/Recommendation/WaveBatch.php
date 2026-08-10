<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * One batch of the frozen plan taking part in a provider wave (#344): its
 * position in the plan, its snapshot-order entry ids, and the prompt lines
 * those ids still resolve to. A batch whose every entry was pruned since the
 * snapshot has an empty `linesById`, and resolves as an empty winner set with
 * no provider call — the per-batch form of providerTick's all-pruned
 * short-circuit.
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
