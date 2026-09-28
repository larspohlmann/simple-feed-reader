<?php

declare(strict_types=1);

namespace App\Service\Catalog\Model;

final readonly class ParsedCatalogModel
{
    /**
     * @param list<CatalogDocumentCategoryModel> $categories
     */
    public function __construct(
        public array $categories,
    ) {
    }

    public function feedCount(): int
    {
        return array_sum(array_map(
            static fn (CatalogDocumentCategoryModel $category): int => \count($category->feeds),
            $this->categories,
        ));
    }
}
