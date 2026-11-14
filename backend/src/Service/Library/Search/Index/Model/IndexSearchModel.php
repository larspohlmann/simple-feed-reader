<?php

declare(strict_types=1);

namespace App\Service\Search\Index\Model;

use App\Enum\ListOrder;
use App\Pagination\EntryCursor;
use App\Service\Search\Model\SearchTermsModel;

/**
 * One search read for whichever engine sits behind SearchIndexReaderInterface. It carries the whole SearchTermsModel,
 * because words split from their mode lost whole-word matching on the way to the engine (#450).
 */
final readonly class IndexSearchModel
{
    /**
     * @param list<int>|null $feedIds  the feeds the caller may see; null for every feed.
     *                                 Never empty: a caller with no feeds answers empty itself
     * @param list<int>|null $entryIds when set, only these entries are candidates
     */
    public function __construct(
        public SearchTermsModel $terms,
        public ?array $feedIds,
        public ?EntryCursor $cursor,
        public int $limit,
        public ?array $entryIds = null,
        public ListOrder $order = ListOrder::NewestFirst,
    ) {
        if ($feedIds === []) {
            throw new \InvalidArgumentException('A search over no feeds must not reach the engine.');
        }
    }

    /**
     * A membership probe: which of exactly these entries match, on every
     * feed. The limit is the candidate count, so no member is dropped.
     *
     * @param non-empty-list<int> $entryIds
     */
    public static function amongEntries(SearchTermsModel $terms, array $entryIds): self
    {
        return new self($terms, null, null, \count($entryIds), $entryIds);
    }
}
