<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Run\Support;

final class ConsolidationShortlist
{
    /**
     * The best entries the consolidation call re-scores and dedupes. How many is what the connection's context window
     * holds, not a multiple of the final list.
     *
     * @param list<array{id: int, score: int, reason: string}> $ranked
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    public static function of(array $ranked, int $inputSize): array
    {
        return \array_slice($ranked, 0, $inputSize);
    }

    private function __construct()
    {
    }
}
