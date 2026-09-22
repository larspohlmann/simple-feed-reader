<?php

declare(strict_types=1);

namespace App\Service\Search\Membership;

use App\Service\Search\SearchEngineCapability;

/**
 * The engine when one is configured, the database otherwise — and never the
 * database as a fallback when the engine fails: a table filled by two
 * matchers with different recall would be sticky and wrong, so an engine
 * failure stops the sweep for this run instead (see SavedSearchMembershipSweep).
 */
final readonly class EngineOrDatabaseSavedSearchMatcher implements SavedSearchMatcher
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
