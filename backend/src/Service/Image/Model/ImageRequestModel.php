<?php

declare(strict_types=1);

namespace App\Service\Image\Model;

final readonly class ImageRequestModel
{
    public function __construct(
        public string $url,
        public string $cookies = '',
    ) {
    }
}
