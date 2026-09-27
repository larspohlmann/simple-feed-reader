<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Enum\SourceFormat;

final readonly class CatalogFeedDetails
{
    public function __construct(
        public int $categoryId,
        public string $title,
        public string $url,
        public ?string $siteUrl = null,
        public ?string $description = null,
        public string $sourceFormat = SourceFormat::XML,
        public bool $enabled = true,
        public bool $locked = true,
    ) {
    }
}
