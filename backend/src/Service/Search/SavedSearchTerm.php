<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * One saved search as the search domain runs it: what it matches, and which
 * search it is. Paired in one value so no reader has to align two lists.
 */
final readonly class SavedSearchTerm
{
    public function __construct(
        public int $id,
        public SearchTerms $terms,
    ) {
    }
}
