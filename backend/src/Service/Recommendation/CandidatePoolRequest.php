<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * What bounds one run's candidate pool: how far back it reaches, how many
 * entries it may hold, and the seed that shuffles it. The three travel
 * together because they describe one selection, and the caller — not the
 * loader — decides them: the loader stays clock-free and settings-free, so a
 * test can pin an exact boundary instead of arranging a clock.
 *
 * `since` is an absolute instant, already resolved from the reader's
 * lookbackDays against the snapshot clock, so nothing downstream has to know
 * what "2 days" meant at that moment.
 */
final readonly class CandidatePoolRequest
{
    public function __construct(
        public \DateTimeImmutable $since,
        public int $poolSize,
        public int $orderSeed,
    ) {
    }
}
