<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

/** A resolved batch wave: the winners in plan order, and whether a 429 was seen, which halves the run's concurrency. */
final readonly class BatchWaveResultModel
{
    /**
     * @param list<list<array{id: int, score: int, reason: string}>> $winners
     */
    public function __construct(
        public array $winners,
        public bool $rateLimitObserved,
    ) {
    }
}
