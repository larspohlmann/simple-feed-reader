<?php

declare(strict_types=1);

namespace App\Service\Search;

final readonly class SavedSearchTally
{
    /**
     * @param list<int> $unreadEntryIds
     */
    public function __construct(
        public array $unreadEntryIds,
        public int $memberCount,
    ) {
    }
}
