<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every candidate source over the raw page and returns what they find, in
 * source order, capped.
 *
 * It reads the raw HTML rather than FetchedPageNormalizer's document on purpose:
 * that pass strips <script> blocks from the string before parsing, and ARD keeps
 * its renditions in player JSON. Working from the source costs one extra parse,
 * the same trade collapseWrapperChains() already makes.
 */
final readonly class PageMediaScanner
{
    /** @param iterable<MediaCandidateSourceInterface> $sources */
    public function __construct(
        #[AutowireIterator('app.media_candidate_source')]
        private iterable $sources,
    ) {
    }

    public function scan(string $pageHtml, string $pageUrl): ArticleMedia
    {
        $found = [];
        foreach ($this->sources as $source) {
            foreach ($source->find($pageHtml, $pageUrl) as $candidate) {
                $found[$candidate->url] = $candidate;
            }
        }

        return new ArticleMedia(\array_slice(array_values($found), 0, ArticleMedia::MAX_ITEMS));
    }
}
