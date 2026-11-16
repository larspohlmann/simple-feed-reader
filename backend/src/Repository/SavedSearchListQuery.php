<?php

declare(strict_types=1);

namespace App\Repository;

/** Everything one combined saved-search read needs: the caller, the searches by id, and the page. */
final readonly class SavedSearchListQuery
{
    /**
     * @param list<int> $savedSearchIds
     */
    public function __construct(
        public int $userId,
        public array $savedSearchIds,
        public EntryListPage $page = new EntryListPage(),
    ) {
    }
}
