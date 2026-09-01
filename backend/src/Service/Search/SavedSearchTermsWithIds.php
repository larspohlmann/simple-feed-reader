<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * A user's saved searches as SavedSearchTerms::forUser() reads them, paired
 * with the id of the search each entry came from — same order, one read.
 */
final readonly class SavedSearchTermsWithIds
{
    /**
     * @param list<SearchTerms> $terms
     * @param list<int>         $ids
     */
    public function __construct(
        public array $terms,
        public array $ids,
    ) {
    }
}
