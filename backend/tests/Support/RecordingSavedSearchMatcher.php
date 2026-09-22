<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Search\Membership\SavedSearchMatcher;
use App\Service\Search\SavedSearchTerm;

/**
 * Answers from a fixed map of saved-search id => matching entry ids, keeps
 * every call it received, and can be told to fail — so a sweep test can
 * assert exactly which candidates were asked about and what happened after.
 */
final class RecordingSavedSearchMatcher implements SavedSearchMatcher
{
    /** @var list<array{searchIds: list<int>, candidates: list<int>}> */
    public array $calls = [];

    /**
     * @param array<int, list<int>> $matchesBySearchId
     */
    public function __construct(
        private readonly array $matchesBySearchId = [],
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function matchingIds(array $searches, array $candidateEntryIds): array
    {
        $this->calls[] = ['searchIds' => SavedSearchTerm::idsOf($searches), 'candidates' => $candidateEntryIds];
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $matches = [];
        foreach ($searches as $search) {
            $matches[$search->id] = array_values(array_intersect(
                $this->matchesBySearchId[$search->id] ?? [],
                $candidateEntryIds,
            ));
        }

        return $matches;
    }
}
