<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every candidate source over the raw page, highest priority first, and
 * merges what they find by URL: the first to name a URL sets the candidate
 * and its position; later sources fill its gaps and add unnamed URLs (#788).
 *
 * It reads the raw HTML rather than FetchedPageNormalizer's document on purpose:
 * that pass is tuned for readability scoring and removes elements, so discovery
 * must not depend on it. Working from the source costs one extra parse, the
 * same trade collapseWrapperChains() already makes.
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
        $byUrl = [];
        foreach ($this->sources as $source) {
            foreach ($source->find($pageHtml, $pageUrl) as $candidate) {
                $byUrl[$candidate->url] = isset($byUrl[$candidate->url])
                    ? $byUrl[$candidate->url]->completedBy($candidate)
                    : $candidate;
            }
        }

        return new ArticleMedia(\array_slice(array_values($byUrl), 0, ArticleMedia::MAX_ITEMS));
    }
}
