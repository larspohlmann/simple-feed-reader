<?php

declare(strict_types=1);

namespace App\Service\Search\Membership;

use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\SavedSearchTerm;

/**
 * Which of a handful of candidate entries each saved search matches — the one
 * question the membership sweep asks, on whichever engine the host has (#1116).
 */
interface SavedSearchMatcher
{
    /**
     * @param list<SavedSearchTerm> $searches
     * @param list<int>             $candidateEntryIds
     *
     * @return array<int, list<int>> every requested saved-search id => the candidate ids it matches
     *
     * @throws SearchEngineUnavailableException
     */
    public function matchingIds(array $searches, array $candidateEntryIds): array;
}
