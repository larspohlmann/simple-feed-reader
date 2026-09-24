<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\ListOrder;

/**
 * The instant a list ranks by and the direction it runs in. One value, because
 * the ORDER BY, the keyset predicate and the tag window must all read the same pair.
 */
final readonly class EntryListOrdering
{
    public function __construct(
        public EntryListSort $sort,
        public ListOrder $order = ListOrder::NewestFirst,
    ) {
    }
}
