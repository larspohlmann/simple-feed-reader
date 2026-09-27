<?php

declare(strict_types=1);

namespace App\Service\Admin;

final readonly class UserFootprint
{
    public function __construct(
        public int $feedsCount,
        public int $tagsCount,
        public int $feedsLimit,
        public int $staleFeedsCount,
        public ?\DateTimeImmutable $lastRefreshAt,
        public bool $dormant,
    ) {
    }
}
