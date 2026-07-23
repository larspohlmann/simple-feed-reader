<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * Seam over ArticleExtractor so the reader endpoint can be driven with a
 * controlled outcome in tests without a real outbound page fetch. Production
 * binds this to ArticleExtractor; the functional test swaps a fake via the
 * public alias in services_test.yaml.
 */
interface ArticleExtractorInterface
{
    public function extract(string $url): ExtractionResult;
}
