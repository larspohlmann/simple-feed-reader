<?php

declare(strict_types=1);

namespace App\Dto\Entry;

use App\Repository\EntryQuery;

/** The paging, unread and order parameters every entry list reads from its query string. */
final readonly class EntryPageParameters
{
    public function __construct(
        public ?string $cursor = null,
        public int $limit = EntryQuery::DEFAULT_LIMIT,
        public bool $unread = false,
        public ?string $order = null,
    ) {
    }
}
