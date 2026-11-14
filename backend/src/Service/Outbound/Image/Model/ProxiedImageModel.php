<?php

declare(strict_types=1);

namespace App\Service\Image\Model;

final readonly class ProxiedImageModel
{
    public function __construct(
        public string $bytes,
        public string $contentType,
    ) {
    }
}
