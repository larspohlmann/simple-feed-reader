<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/**
 * What one account has built up, as the admin detail screen shows it.
 */
final readonly class UserFootprint
{
    public function __construct(
        public int $feedsCount,
        public int $tagsCount,
        /** The cap the subscribe path enforces today; per-user caps arrive with #66. */
        public int $feedsLimit,
        public int $staleFeedsCount,
        /** Newest fetch across the account's feeds; null when it has no feeds. */
        public ?\DateTimeImmutable $lastRefreshAt,
        public bool $dormant,
    ) {
    }
}
