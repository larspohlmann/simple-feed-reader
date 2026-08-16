<?php

declare(strict_types=1);

namespace App\Service\Search\Index;

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
     * @param list<int> $feedIds the feeds the caller may see; never asked
     *                           of the engine when empty
     */
    public function __construct(
        public SearchTerms $terms,
        public array $feedIds,
        public ?EntryCursor $cursor,
        public int $limit,
    ) {
    }
}
