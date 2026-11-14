<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard\PageCardReader;

use App\Service\Content\LinkCard\Model\PageCardModel;
use App\Service\Content\Reader\Exception\PageFetchException;

interface PageCardReaderInterface
{
    /** @throws PageFetchException */
    public function read(string $sharedUrl): PageCardModel;
}
