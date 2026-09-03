<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The outcome of parsing one consolidation reply, answering two questions:
 * which shortlisted posts to recommend, and which of those duplicate a
 * better-scored one. `usable` follows the picks alone -- empty `duplicateIds`
 * is a legitimate "none of them", but zero picks means the model recommended
 * nothing and the whole reply is unusable.
 */
final readonly class ConsolidationParseResult
{
    /**
     * @param list<RecommendationPick> $picks
     * @param list<int>                $duplicateIds
     */
    private function __construct(
        public array $picks,
        public array $duplicateIds,
        public bool $usable,
    ) {
    }

    /**
     * @param list<RecommendationPick> $picks
     * @param list<int>                $duplicateIds
     */
    public static function usable(array $picks, array $duplicateIds): self
    {
        return new self($picks, $duplicateIds, true);
    }

    public static function unusable(): self
    {
        return new self([], [], false);
    }
}
