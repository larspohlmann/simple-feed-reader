<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every candidate source over the raw page, highest priority first, and
 * lets the first source to yield a MediaKind own it, capped.
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
        $byKind = [];
        foreach ($this->sources as $source) {
            $this->claimUnownedKinds($source->find($pageHtml, $pageUrl), $byKind);
        }

        $found = $byKind === [] ? [] : array_merge(...array_values($byKind));

        return new ArticleMedia(\array_slice($found, 0, ArticleMedia::MAX_ITEMS));
    }

    /**
     * @param list<MediaCandidate>                 $candidates
     * @param array<string, list<MediaCandidate>>  $byKind
     */
    private function claimUnownedKinds(array $candidates, array &$byKind): void
    {
        $bySourceKind = [];
        foreach ($candidates as $candidate) {
            $bySourceKind[$candidate->kind->value][] = $candidate;
        }

        foreach ($bySourceKind as $kind => $candidatesOfKind) {
            $byKind[$kind] ??= $candidatesOfKind;
        }
    }
}
