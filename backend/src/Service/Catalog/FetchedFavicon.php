<?php

declare(strict_types=1);

namespace App\Service\Catalog;

final readonly class FetchedFavicon
{
    public function __construct(
        public string $sourceUrl,
        public string $bytes,
        public string $contentType,
    ) {
    }
}
