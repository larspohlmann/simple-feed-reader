<?php

declare(strict_types=1);

namespace App\Service\Reader\ArticleExtractor;

use App\Service\Reader\Model\EntryHintsModel;
use App\Service\Reader\Model\ExtractionResultModel;

/** The reader endpoint's seam: tests swap in a fake with the test container's set(). */
interface ArticleExtractorInterface
{
    public function extract(string $url, EntryHintsModel $hints = new EntryHintsModel()): ExtractionResultModel;
}
