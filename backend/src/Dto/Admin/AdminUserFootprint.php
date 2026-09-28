<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/**
 * The wire shape of a {@see \App\Service\Admin\Model\UserFootprintModel}: the same figures,
 * the datetime formatted for JSON.
 */
final readonly class AdminUserFootprint
{
    public function __construct(
        public int $feedsCount,
        public int $tagsCount,
        public int $feedsLimit,
        public int $staleFeedsCount,
        public ?string $lastRefreshAt,
        public bool $dormant,
    ) {
    }
}
