<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * What RecommendationConsolidationResolver settled a consolidation call to,
 * handed back for the advancer to write. A usable reply -- or an all-pruned
 * pool with nothing left to consolidate -- carries the final ranked,
 * deduped, reasoned list to finalize; an unusable one carries the offending
 * reply and the batch-score pool to degrade to, so the advancer can run its
 * cross-tick retry-or-degrade envelope, mirroring DedupOutcome. Transport
 * failures never reach here: the resolver throws them, exactly as
 * RecommendationDedupResolver and RecommendationBatchWave do.
 */
final readonly class ConsolidationOutcome
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

    /**
     * The reply the consolidation call could not use, for the advancer's
     * retry-or-degrade envelope. Only an unusable outcome has one.
     */
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
