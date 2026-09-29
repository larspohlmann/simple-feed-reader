<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow\SlideshowRecognizer;

use App\Service\Reader\Media\Model\PageTextBlocksModel;
use App\Service\Reader\Slideshow\Model\SlideshowModel;
use Dom\HTMLDocument;

interface SlideshowRecognizerInterface
{
    /** @return list<SlideshowModel> */
    public function recognize(HTMLDocument $document, PageTextBlocksModel $textBlocks): array;
}
