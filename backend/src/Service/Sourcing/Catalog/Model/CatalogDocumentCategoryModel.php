<?php

declare(strict_types=1);

namespace App\Service\Catalog\Model;

final readonly class CatalogDocumentCategoryModel
{
    /**
     * @param list<CatalogDocumentFeedModel> $feeds
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $icon,
        public string $color,
        public array $feeds,
    ) {
    }
}
