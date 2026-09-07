<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use App\Service\Reader\Media\PageTextBlocks;
use Dom\HTMLDocument;

interface SlideshowRecognizerInterface
{
    /** @return list<Slideshow> */
    public function recognize(HTMLDocument $document, PageTextBlocks $textBlocks): array;
}
