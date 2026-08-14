<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The fetch-order position of one feed's `keep`-th newest entry: `createdAt`
 * plus `id` as the tie-break, exactly as `EntryPruner` ranks (later fetch =
 * newer; id breaks a tie inside one run).
 *
 * A boundary rather than a rank number, so the entries beyond it can be
 * selected with a keyset comparison — `(createdAt, id) < (this, this)` — an
 * index-servable range, instead of a correlated count that re-scans the
 * whole feed once per row.
 */
final readonly class EntryRankBoundary
{
    public function __construct(
        public \DateTimeImmutable $createdAt,
        public int $id,
    ) {
    }
}
