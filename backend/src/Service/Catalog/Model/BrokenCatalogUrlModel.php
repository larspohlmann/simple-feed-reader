<?php

declare(strict_types=1);

namespace App\Service\Catalog\Model;

final readonly class BrokenCatalogUrlModel
{
    public function __construct(
        public string $title,
        public string $url,
        public string $reason,
    ) {
    }
}
