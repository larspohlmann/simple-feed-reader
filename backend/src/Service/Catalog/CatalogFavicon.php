<?php

declare(strict_types=1);

namespace App\Service\Catalog;

final readonly class CatalogFavicon
{
    public function __construct(
        public string $bytes,
        public string $contentType,
    ) {
    }
}
