<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Pool\Model;

/**
 * What bounds one run's candidate pool: its reach, its size and its shuffle seed. The caller decides them, so the
 * loader stays clock- and settings-free; `since` is already resolved from lookbackDays against the snapshot clock.
 */
final readonly class CandidatePoolRequestModel
{
    public function __construct(
        public \DateTimeImmutable $since,
        public int $poolSize,
        public int $orderSeed,
    ) {
    }
}
