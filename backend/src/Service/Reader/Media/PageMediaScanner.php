<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every source over the raw page, highest priority first, and merges by URL: the first to name a URL
 * sets the candidate and its place; a later source fills its gaps and adds new URLs of a kind only when it
 * re-confirms one an earlier source set — proof it sees this article's media, not a rendition (#788).
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

    public function scan(string $pageHtml, string $pageUrl, ?string $fallbackPoster = null): ArticleMedia
    {
        $byUrl = [];
        foreach ($this->sources as $source) {
            $this->mergeSource($source->find($pageHtml, $pageUrl), $byUrl);
        }

        // Every source has spoken and their posters are merged, so a video that
        // is still poster-less has none from the page: the feed-declared still
        // rescues it, or it is dropped before it reaches the client (#913).
        $withPosters = $this->withResolvedPosters(array_values($byUrl), $fallbackPoster);

        return (new ArticleMedia(\array_slice($withPosters, 0, ArticleMedia::MAX_ITEMS)))
            ->withoutRedundantStreams();
    }

    /**
     * @param list<MediaCandidate> $candidates
     *
     * @return list<MediaCandidate>
     */
    private function withResolvedPosters(array $candidates, ?string $fallbackPoster): array
    {
        $resolved = [];
        foreach ($candidates as $candidate) {
            $rescued = $candidate->resolvePoster($fallbackPoster);
            if ($rescued !== null) {
                $resolved[] = $rescued;
            }
        }

        return $resolved;
    }

    /**
     * @param list<MediaCandidate>          $candidates
     * @param array<string, MediaCandidate> $byUrl
     */
    private function mergeSource(array $candidates, array &$byUrl): void
    {
        $claimedKinds = $this->kinds($byUrl);
        $reconfirmedKinds = $this->reconfirmedKinds($candidates, $byUrl);
        foreach ($candidates as $candidate) {
            if (isset($byUrl[$candidate->url])) {
                $byUrl[$candidate->url] = $byUrl[$candidate->url]->completedBy($candidate);
                continue;
            }
            if ($this->mayAdd($candidate, $claimedKinds, $reconfirmedKinds)) {
                $byUrl[$candidate->url] = $candidate;
            }
        }
    }

    /**
     * A new URL joins when no earlier source claimed its kind, or this source re-confirms that kind — proof
     * it sees this article's media, not a rendition or an unrelated file of an already-claimed kind (#788).
     *
     * @param array<string, true> $claimedKinds
     * @param array<string, true> $reconfirmedKinds
     */
    private function mayAdd(MediaCandidate $candidate, array $claimedKinds, array $reconfirmedKinds): bool
    {
        return !isset($claimedKinds[$candidate->kind->value])
            || isset($reconfirmedKinds[$candidate->kind->value]);
    }

    /**
     * @param array<string, MediaCandidate> $byUrl
     *
     * @return array<string, true> the kinds an earlier source has already set
     */
    private function kinds(array $byUrl): array
    {
        $kinds = [];
        foreach ($byUrl as $candidate) {
            $kinds[$candidate->kind->value] = true;
        }

        return $kinds;
    }

    /**
     * @param list<MediaCandidate>          $candidates
     * @param array<string, MediaCandidate> $byUrl
     *
     * @return array<string, true> the kinds this source re-confirms by naming an already-set URL
     */
    private function reconfirmedKinds(array $candidates, array $byUrl): array
    {
        $kinds = [];
        foreach ($candidates as $candidate) {
            if (isset($byUrl[$candidate->url])) {
                $kinds[$candidate->kind->value] = true;
            }
        }

        return $kinds;
    }
}
