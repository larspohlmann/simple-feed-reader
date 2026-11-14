<?php

declare(strict_types=1);

namespace App\Service\Catalog\Model;

final readonly class CatalogDocumentFeedModel
{
    public function __construct(
        public string $title,
        public string $url,
        public ?string $siteUrl,
        public ?string $description,
        public string $sourceFormat,
    ) {
    }
}
