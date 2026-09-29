<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt\Model;

/**
 * The outcome of parsing one assistant reply. `usable` is what Tasks 10-11
 * branch on: a usable result's picks get recorded and the run advances; an
 * unusable one triggers a retry with a corrective message.
 */
final readonly class PickParseResultModel
{
    /** @param list<RecommendationPickModel> $picks */
    private function __construct(
        public array $picks,
        public bool $usable,
    ) {
    }

    /** @param list<RecommendationPickModel> $picks */
    public static function usable(array $picks): self
    {
        return new self($picks, true);
    }

    public static function unusable(): self
    {
        return new self([], false);
    }
}
