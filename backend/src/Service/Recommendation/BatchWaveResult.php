<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * A resolved batch wave: the winners in plan order, and whether any 429 was
 * observed this tick — which halves the run's wave concurrency (#947).
 */
final readonly class BatchWaveResult
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
