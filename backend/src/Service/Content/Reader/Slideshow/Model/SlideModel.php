<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow\Model;

final readonly class SlideModel
{
    public function __construct(
        public string $imageUrl,
        public string $alt,
        public SlideCaptionModel $caption = new SlideCaptionModel('', null),
    ) {
    }
}
