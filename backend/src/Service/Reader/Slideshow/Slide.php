<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

final readonly class Slide
{
    public function __construct(
        public string $imageUrl,
        public string $alt,
    ) {
    }
}
