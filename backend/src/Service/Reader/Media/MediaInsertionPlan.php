<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;

/**
 * A read-only classification of where recovered media belongs: each pair
 * names a body `<img>` to swap for a player in place, and the remainder go to
 * the top, in source order. Built by `PageMediaInserter::plan()` before
 * `ReaderLeadImage::restore()` runs, so restore can consult `hasTopPlaced()`
 * without any document mutation happening first (see ReaderBodyCleaner).
 */
final readonly class MediaInsertionPlan
{
    /**
     * @param list<array{image: Element, candidate: MediaCandidate}> $reconcilePairs
     * @param list<MediaCandidate>                                  $topPlaced
     */
    public function __construct(
        public array $reconcilePairs,
        public array $topPlaced,
    ) {
    }

    public function hasTopPlaced(): bool
    {
        return $this->topPlaced !== [];
    }
}
