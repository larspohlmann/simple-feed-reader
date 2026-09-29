<?php

declare(strict_types=1);

namespace App\Service\Search\Membership\SavedSearchMatcher;

use App\Repository\DatabaseSavedSearchMatcher;
use App\Service\Search\SearchEngineCapability;

/**
 * The engine when one is configured, the database otherwise, and never the database after an engine failure: two
 * matchers with different recall would leave sticky, wrong rows, so a failure stops this run's sweep instead.
 */
final readonly class EngineOrDatabaseSavedSearchMatcher implements SavedSearchMatcherInterface
{
    public function __construct(
        private IndexedSavedSearchMatcher $engine,
        private DatabaseSavedSearchMatcher $database,
        private SearchEngineCapability $capability,
    ) {
    }

    public function matchingIds(array $searches, array $candidateEntryIds): array
    {
        return ($this->capability->isConfigured() ? $this->engine : $this->database)
            ->matchingIds($searches, $candidateEntryIds);
    }
}
