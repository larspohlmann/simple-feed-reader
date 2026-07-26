<?php

declare(strict_types=1);

namespace App\Service\Catalog;

final readonly class ParsedCatalog
{
    /**
     * @param list<CatalogDocumentCategory> $categories
     */
    public function __construct(
        public array $categories,
    ) {
    }

    public function feedCount(): int
    {
        return array_sum(array_map(
            static fn (CatalogDocumentCategory $category): int => \count($category->feeds),
            $this->categories,
        ));
    }
}
