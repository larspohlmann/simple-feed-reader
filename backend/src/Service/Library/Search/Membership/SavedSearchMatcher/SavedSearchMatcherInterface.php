<?php

declare(strict_types=1);

namespace App\Service\Library\Search\Membership\SavedSearchMatcher;

use App\Service\Library\Search\Exception\SearchEngineUnavailableException;
use App\Service\Library\Search\Model\SavedSearchTermModel;

/**
 * Which of a handful of candidate entries each saved search matches — the one
 * question the membership sweep asks, on whichever engine the host has.
 */
interface SavedSearchMatcherInterface
{
    /**
     * @param list<SavedSearchTermModel> $searches
     * @param list<int>                  $candidateEntryIds
     *
     * @return array<int, list<int>> every requested saved-search id => the candidate ids it matches
     *
     * @throws SearchEngineUnavailableException
     */
    public function matchingIds(array $searches, array $candidateEntryIds): array;
}
