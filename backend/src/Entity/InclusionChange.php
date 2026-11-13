<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\InclusionSurface;

/** Which surfaces to switch in or out; a null switch stays as it is. */
final readonly class InclusionChange
{
    public function __construct(
        public ?bool $allItems = null,
        public ?bool $forYou = null,
        public ?bool $savedSearches = null,
        public ?bool $trending = null,
    ) {
    }

    public function switchFor(InclusionSurface $surface): ?bool
    {
        return match ($surface) {
            InclusionSurface::AllItems => $this->allItems,
            InclusionSurface::ForYou => $this->forYou,
            InclusionSurface::SavedSearches => $this->savedSearches,
            InclusionSurface::Trending => $this->trending,
        };
    }
}
