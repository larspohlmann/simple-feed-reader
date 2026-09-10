<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\SavedSearchEntryQuery;

/**
 * The combined saved-search list as one operation, so the engine and the
 * database expose the same shape and services.yaml can swap them behind
 * SavedSearchEntriesWithFallback (mirrors EntrySearchInterface, #432/#973).
 */
interface SavedSearchEntriesInterface
{
    public function list(SavedSearchEntryQuery $query): SavedSearchEntriesResult;
}
