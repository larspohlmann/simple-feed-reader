<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Model;

use Dom\Element;

/**
 * Where recovered media belongs, classified before anything mutates the body: the body `<img>` each player
 * replaces, the block each player follows, and the rest for the top, in source order (see PageMediaPlacement).
 */
final readonly class MediaInsertionPlanModel
{
    /**
     * @param list<array{image: Element, candidate: MediaCandidateModel}> $reconcilePairs
     * @param list<array{block: Element, candidate: MediaCandidateModel}> $anchoredPairs
     * @param list<MediaCandidateModel>                                   $topPlaced
     */
    public function __construct(
        public array $reconcilePairs,
        public array $anchoredPairs,
        public array $topPlaced,
    ) {
    }

    public function topPlacesLeadVisual(): bool
    {
        return array_any(
            $this->topPlaced,
            static fn (MediaCandidateModel $candidate): bool => $candidate->kind->readsAsLeadVisual(),
        );
    }
}
