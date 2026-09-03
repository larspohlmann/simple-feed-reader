<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\SavedSearch;
use App\Repository\SavedSearchEntryRepository;

/**
 * The unread matching entry ids behind each saved search's sidebar badge. The
 * client counts them, and drops one the moment the user reads it, so the badge
 * falls without another scan. Matched through the search's own term matching,
 * so the set is exactly what opening the search would list — and read for
 * every search in one scan, not one scan per search (#584).
 */
final readonly class SavedSearchMatchIds
{
    public function __construct(private SavedSearchEntryRepository $entries)
    {
    }

    /**
     * @param list<SavedSearch> $savedSearches
     *
     * @return array<int, list<int>> saved-search id => unread matching entry ids
     */
    public function forAll(array $savedSearches, int $userId): array
    {
        return $this->entries->unreadMatchIdsBySavedSearch(
            $userId,
            array_map(SavedSearchTerms::termOf(...), $savedSearches),
        );
    }

    /**
     * A batch of one answers one list under the search's id; this unwraps it.
     *
     * @return list<int>
     */
    public function forOne(SavedSearch $savedSearch, int $userId): array
    {
        return array_merge(...$this->forAll([$savedSearch], $userId));
    }
}
