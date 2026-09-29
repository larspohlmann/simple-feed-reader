<?php

declare(strict_types=1);

namespace App\Service\Reader\ArticleExtractor;

use App\Service\Reader\Model\EntryHintsModel;
use App\Service\Reader\Model\ExtractionResultModel;

/** The reader endpoint's seam: tests swap in a fake through the public alias in services_test.yaml. */
interface ArticleExtractorInterface
{
    public function extract(string $url, EntryHintsModel $hints = new EntryHintsModel()): ExtractionResultModel;
}
