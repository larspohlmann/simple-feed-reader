<?php

declare(strict_types=1);

namespace App\Service\Catalog;

/**
 * One budgeted slice of warming. `remaining` is what a caller polls on — the
 * same shape RefreshReport uses, so the frontend drives this with the loop it
 * already has.
 */
final readonly class CatalogWarmReport
{
    public function __construct(
        public int $warmed = 0,
        public int $failed = 0,
        public int $remaining = 0,
    ) {
    }
}
