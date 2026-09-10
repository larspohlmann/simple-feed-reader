<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\SavedSearchEntryRepository;

/**
 * The LIKE mark-read set the reader ships today, behind the shared interface so
 * SavedSearchUnreadMatchesWithFallback can choose it when no engine answers.
 * A thin adapter: the repository already computes exactly this set.
 */
final readonly class DatabaseSavedSearchUnreadMatches implements SavedSearchUnreadMatchSource
{
    public function __construct(private SavedSearchEntryRepository $entries)
    {
    }

    public function unreadMatchIdsUpTo(int $userId, array $savedSearches, \DateTimeImmutable $until): array
    {
        return $this->entries->unreadMatchIdsForSavedSearches($userId, $savedSearches, $until);
    }
}
