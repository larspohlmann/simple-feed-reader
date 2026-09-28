<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;

/**
 * Where recovered media belongs, classified before anything mutates the body: the body `<img>` each player
 * replaces, the block each player follows, and the rest for the top, in source order (see PageMediaPlacement).
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
