<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\EntryCursor;
use App\Service\Search\SearchTerms;

/**
 * The parameter object for a search read. Sits beside EntryQuery because it is
 * the same kind of thing: everything one repository read needs, in one value.
 */
final readonly class EntrySearchQuery
{
    public function __construct(
        public int $userId,
        public SearchTerms $terms,
        public ?EntryCursor $cursor = null,
        public int $limit = EntryQuery::DEFAULT_LIMIT,
    ) {
    }
}
