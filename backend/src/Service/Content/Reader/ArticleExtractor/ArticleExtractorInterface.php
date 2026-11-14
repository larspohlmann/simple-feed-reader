<?php

declare(strict_types=1);

namespace App\Service\Content\Reader\ArticleExtractor;

use App\Service\Content\Reader\Model\EntryHintsModel;
use App\Service\Content\Reader\Model\ExtractionResultModel;

/** The reader endpoint's seam: tests swap in a fake with the test container's set(). */
interface ArticleExtractorInterface
{
    public function extract(string $url, EntryHintsModel $hints = new EntryHintsModel()): ExtractionResultModel;
}
