<?php

declare(strict_types=1);

namespace App\Service\Search\Index;

use App\Enum\ListOrder;
use App\Http\EntryCursor;
use App\Service\Search\SearchTerms;

/**
 * One search read, addressed to whichever engine sits behind SearchIndexReader.
 * A value object rather than loose parameters: MeilisearchIndex turns every
 * field here into wire format in one place, and a future second engine reads
 * the same shape without touching the caller.
 *
 * Carries the whole SearchTerms, not its word list: the words and the mode
 * they are matched in are one value, and separating them is precisely how the
 * whole-word mode came to be dropped on the way to the engine while the LIKE
 * engine still honoured it (#450).
 */
final readonly class IndexSearch
{
    /**
     * @param list<int>|null $feedIds  the feeds the caller may see; null for every feed.
     *                                 Never empty: a caller with no feeds answers empty itself
     * @param list<int>|null $entryIds when set, only these entries are candidates
     */
    public function __construct(
        public SearchTerms $terms,
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
     * A membership probe (#1116): which of exactly these entries match, on every
     * feed. The limit is the candidate count, so no member is dropped.
     *
     * @param non-empty-list<int> $entryIds
     */
    public static function amongEntries(SearchTerms $terms, array $entryIds): self
    {
        return new self($terms, null, null, \count($entryIds), $entryIds);
    }
}
