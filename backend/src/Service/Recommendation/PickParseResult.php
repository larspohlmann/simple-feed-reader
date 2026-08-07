<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The outcome of parsing one assistant reply. `usable` is what Tasks 10-11
 * branch on: a usable result's picks get recorded and the run advances; an
 * unusable one triggers a retry with a corrective message.
 */
final readonly class PickParseResult
{
    /** @param list<RecommendationPick> $picks */
    private function __construct(
        public array $picks,
        public bool $usable,
    ) {
    }

    /** @param list<RecommendationPick> $picks */
    public static function usable(array $picks): self
    {
        return new self($picks, true);
    }

    public static function unusable(): self
    {
        return new self([], false);
    }
}
