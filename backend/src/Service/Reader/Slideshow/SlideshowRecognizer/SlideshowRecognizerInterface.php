<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow\SlideshowRecognizer;

use App\Service\Reader\Media\PageTextBlocks;
use App\Service\Reader\Slideshow\Slideshow;
use Dom\HTMLDocument;

interface SlideshowRecognizerInterface
{
    /** @return list<Slideshow> */
    public function recognize(HTMLDocument $document, PageTextBlocks $textBlocks): array;
}
