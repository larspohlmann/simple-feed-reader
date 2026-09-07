<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;

/**
 * A read-only classification of where recovered media belongs: each reconcile
 * pair names a body `<img>` to swap for a player in place, each anchored pair
 * names the body block the player follows, and the remainder go to the top,
 * in source order. Built by `PageMediaInserter::plan()` before
 * `ReaderLeadImage::restore()` runs, so restore can consult
 * `topPlacesLeadVisual()` without any document mutation happening first
 * (see ReaderBodyCleaner).
 */
final readonly class MediaInsertionPlan
{
    /**
     * @param list<array{image: Element, candidate: MediaCandidate}> $reconcilePairs
     * @param list<array{block: Element, candidate: MediaCandidate}> $anchoredPairs
     * @param list<MediaCandidate>                                  $topPlaced
     */
    public function __construct(
        public array $reconcilePairs,
        public array $anchoredPairs,
        public array $topPlaced,
    ) {
    }

    /**
     * A top-placed video or embed takes the article's lead position, so the page
     * hero must not stack above it; a top-placed audio player (narration, a
     * podcast) leaves the hero its place (#907).
     */
    public function topPlacesLeadVisual(): bool
    {
        return array_any($this->topPlaced, static fn (MediaCandidate $c): bool => $c->kind->readsAsLeadVisual());
    }
}
