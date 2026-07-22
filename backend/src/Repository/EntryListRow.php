<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;

/**
 * One row of the entry list: the shared Entry plus the caller-specific view of
 * it. `isRead` already has the subscription watermark folded in, so the client
 * never re-derives it. `subscriptionId`/`subscriptionTitle` identify the source
 * for a cross-feed listing.
 */
final readonly class EntryListRow
{
    public function __construct(
        public Entry $entry,
        public int $subscriptionId,
        public string $subscriptionTitle,
        public bool $isRead,
        public bool $isFavorite,
        public bool $isKept,
    ) {
    }
}
