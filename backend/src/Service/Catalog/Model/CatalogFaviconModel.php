<?php

declare(strict_types=1);

namespace App\Service\Catalog\Model;

final readonly class CatalogFaviconModel
{
    public function __construct(
        public string $bytes,
        public string $contentType,
    ) {
    }
}
