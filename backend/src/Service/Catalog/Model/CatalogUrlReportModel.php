<?php

declare(strict_types=1);

namespace App\Service\Catalog\Model;

final readonly class CatalogUrlReportModel
{
    /** @param list<BrokenCatalogUrlModel> $broken */
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
