<?php

declare(strict_types=1);

namespace App\Service\Ai\Llm\Prompt\Model;

/**
 * One consolidation reply's picks and the ids among them that duplicate a better-scored one. `usable` follows the
 * picks alone: no duplicates is a valid answer, no picks is not.
 */
final readonly class ConsolidationParseResultModel
{
    /**
     * @param list<RecommendationPickModel> $picks
     * @param list<int>                     $duplicateIds
     */
    private function __construct(
        public array $picks,
        public array $duplicateIds,
        public bool $usable,
    ) {
    }

    /**
     * @param list<RecommendationPickModel> $picks
     * @param list<int>                     $duplicateIds
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
