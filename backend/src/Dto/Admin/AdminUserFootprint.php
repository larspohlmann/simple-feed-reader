<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/**
 * The JSON-ready shape of a {@see UserFootprint}: the same figures, with the
 * datetime formatted for the wire instead of left as a DateTimeImmutable.
 * Kept distinct from UserFootprint so the domain calculation (Service\Admin\
 * UserStatistics) never has to know about serialisation.
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
