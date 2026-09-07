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
    /**
     * @param string|null    $entryTitle the feed entry's own title, when the caller
     *                                   knows it — extraction uses it to recognize
     *                                   (and drop) a headline repeated in the body
     * @param FeedMedia|null $feedMedia  the media the feed declared for this entry,
     *                                   trusted over the reader's own guesses for a
     *                                   scraped URL it enumerated: real dimensions,
     *                                   the declared kind, the poster fallback (#914)
     */
    public function extract(
        string $url,
        ?string $entryTitle = null,
        ?string $entryAuthor = null,
        ?FeedMedia $feedMedia = null,
    ): ExtractionResult;
}
