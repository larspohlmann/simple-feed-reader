<?php

declare(strict_types=1);

namespace App\Service\Reader\ArticleExtractor;

use App\Service\Reader\EntryHints;
use App\Service\Reader\ExtractionResult;

/** The reader endpoint's seam: tests swap in a fake through the public alias in services_test.yaml. */
interface ArticleExtractorInterface
{
    public function extract(string $url, EntryHints $hints = new EntryHints()): ExtractionResult;
}
