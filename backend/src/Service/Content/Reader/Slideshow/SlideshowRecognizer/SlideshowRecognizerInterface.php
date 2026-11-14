<?php

declare(strict_types=1);

namespace App\Service\Content\Reader\Slideshow\SlideshowRecognizer;

use App\Service\Content\Reader\Media\Model\PageTextBlocksModel;
use App\Service\Content\Reader\Slideshow\Model\SlideshowModel;
use Dom\HTMLDocument;

interface SlideshowRecognizerInterface
{
    /** @return list<SlideshowModel> */
    public function recognize(HTMLDocument $document, PageTextBlocksModel $textBlocks): array;
}
