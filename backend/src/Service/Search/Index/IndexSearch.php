<?php

declare(strict_types=1);

namespace App\Service\Search\Index;

use App\Http\EntryCursor;

/**
 * One search read, addressed to whichever engine sits behind SearchIndexReader.
 * A value object rather than loose parameters: MeilisearchIndex turns every
 * field here into wire format in one place, and a future second engine reads
 * the same shape without touching the caller.
 */
final readonly class IndexSearch
{
    /**
     * @param list<string> $terms   what the user typed, already split
     * @param list<int>    $feedIds the feeds the caller may see; never asked
     *                              of the engine when empty
     */
    public function __construct(
        public array $terms,
        public array $feedIds,
        public ?EntryCursor $cursor,
        public int $limit,
    ) {
    }
}
