<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\SavedSearchEntryRepository;

/**
 * The LIKE badge scan the reader ships today, behind the shared interface so
 * SavedSearchBadgesWithFallback can choose it when no engine answers. A thin
 * adapter: the repository already computes exactly this set.
 */
final readonly class DatabaseSavedSearchBadges implements SavedSearchBadgeSource
{
    public function __construct(private SavedSearchEntryRepository $entries)
    {
    }

    public function unreadMatchIdsBySavedSearch(int $userId, array $searches): array
    {
        return $this->entries->unreadMatchIdsBySavedSearch($userId, $searches);
    }
}
