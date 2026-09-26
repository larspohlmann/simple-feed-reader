<?php

declare(strict_types=1);

namespace App\Service\Catalog;

final readonly class CatalogUrlReport
{
    /** @param list<BrokenCatalogUrl> $broken */
    public function __construct(
        public int $checked,
        public array $broken,
    ) {
    }

    public function isHealthy(): bool
    {
        return [] === $this->broken;
    }
}
