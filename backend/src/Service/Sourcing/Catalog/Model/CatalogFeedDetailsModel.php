<?php

declare(strict_types=1);

namespace App\Service\Catalog\Model;

use App\Enum\SourceFormat;

final readonly class CatalogFeedDetailsModel
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
