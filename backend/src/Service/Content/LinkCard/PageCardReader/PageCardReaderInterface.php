<?php

declare(strict_types=1);

namespace App\Service\LinkCard\PageCardReader;

use App\Service\LinkCard\Model\PageCardModel;
use App\Service\Reader\Exception\PageFetchException;

interface PageCardReaderInterface
{
    /** @throws PageFetchException */
    public function read(string $sharedUrl): PageCardModel;
}
