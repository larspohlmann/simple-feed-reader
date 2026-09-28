<?php

declare(strict_types=1);

namespace App\Service\Search\Model;

/**
 * One saved search as the search domain runs it: what it matches, and which
 * search it is. Paired in one value so no reader has to align two lists.
 */
final readonly class SavedSearchTermModel
{
    public function __construct(
        public int $id,
        public SearchTermsModel $terms,
    ) {
    }

    /**
     * @param list<self> $searches
     *
     * @return list<int>
     */
    public static function idsOf(array $searches): array
    {
        return array_map(static fn (self $search): int => $search->id, $searches);
    }
}
