<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

/**
 * What a consolidation call settled to: the final list, or the unusable reply ConsolidationPhase retries and the
 * batch-score pool it degrades to (#493).
 */
final readonly class ConsolidationOutcomeModel
{
    /**
     * @param list<array{id: int, score: int, reason: string}> $ranked usable: the final
     *                                                                  list; unusable: the undeduped pool to degrade to
     */
    private function __construct(
        public bool $usable,
        public array $ranked,
        private ?string $unusableReply,
    ) {
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $ranked
     */
    public static function finalizeWith(array $ranked): self
    {
        return new self(true, $ranked, null);
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $fallbackPool
     */
    public static function unusable(string $reply, array $fallbackPool): self
    {
        return new self(false, $fallbackPool, $reply);
    }

    public function requireUnusableReply(): string
    {
        return $this->unusableReply
            ?? throw new \LogicException('A usable consolidation outcome has no invalid reply to retry.');
    }

    /**
     * The batch-score pool to degrade to once retries run out. Only an
     * unusable outcome carries one; a usable outcome's list is already final.
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    public function requireFallbackPool(): array
    {
        if ($this->usable) {
            throw new \LogicException('A usable consolidation outcome has no fallback pool to degrade to.');
        }

        return $this->ranked;
    }
}
