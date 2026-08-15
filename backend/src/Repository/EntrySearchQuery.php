<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\EntryCursor;
use App\Service\Search\SearchTerms;

/**
 * The parameter object for a search read. Sits beside EntryQuery because it is
 * the same kind of thing: everything one repository read needs, in one value.
 */
final class EntrySearchQuery
{
    /** The effective page size — already clamped, never the raw request value. */
    public readonly int $limit;

    /** @param int $limit the size the client asked for */
    public function __construct(
        public readonly int $userId,
        public readonly SearchTerms $terms,
        public readonly ?EntryCursor $cursor = null,
        int $limit = EntryQuery::DEFAULT_LIMIT,
    ) {
        $this->limit = EntryQuery::clampLimit($limit);
    }
}
