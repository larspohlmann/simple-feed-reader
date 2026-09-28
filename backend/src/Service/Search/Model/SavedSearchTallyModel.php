<?php

declare(strict_types=1);

namespace App\Service\Search\Model;

final readonly class SavedSearchTallyModel
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
