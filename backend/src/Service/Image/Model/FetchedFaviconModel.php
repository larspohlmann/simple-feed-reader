<?php

declare(strict_types=1);

namespace App\Service\Image\Model;

final readonly class FetchedFaviconModel
{
    public function __construct(
        public string $sourceUrl,
        public string $bytes,
        public string $contentType,
    ) {
    }
}
