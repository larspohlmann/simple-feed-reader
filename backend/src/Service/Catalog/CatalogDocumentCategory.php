<?php

declare(strict_types=1);

namespace App\Service\Catalog;

final readonly class CatalogDocumentCategory
{
    /**
     * @param list<CatalogDocumentFeed> $feeds
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
