<?php

declare(strict_types=1);

namespace App\Service\Reader;

/** The reader endpoint's seam: tests swap in a fake through the public alias in services_test.yaml. */
interface ArticleExtractorInterface
{
    public function extract(string $url, EntryHints $hints = new EntryHints()): ExtractionResult;
}
