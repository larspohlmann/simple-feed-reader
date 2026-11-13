<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\InclusionSurface;
use Doctrine\ORM\Mapping as ORM;

/** Which surfaces a subscription or a tag lets its feeds into; every one starts switched in. */
#[ORM\Embeddable]
final readonly class InclusionSwitches
{
    public function __construct(
        #[ORM\Column(name: 'all_items', options: ['default' => true])]
        private bool $allItems = true,
        #[ORM\Column(name: 'for_you', options: ['default' => true])]
        private bool $forYou = true,
        #[ORM\Column(name: 'saved_searches', options: ['default' => true])]
        private bool $savedSearches = true,
        #[ORM\Column(name: 'trending', options: ['default' => true])]
        private bool $trending = true,
    ) {
    }

    public function isIncludedIn(InclusionSurface $surface): bool
    {
        return match ($surface) {
            InclusionSurface::AllItems => $this->allItems,
            InclusionSurface::ForYou => $this->forYou,
            InclusionSurface::SavedSearches => $this->savedSearches,
            InclusionSurface::Trending => $this->trending,
        };
    }

    #[\NoDiscard]
    public function with(InclusionSurface $surface, bool $included): self
    {
        return clone($this, [$surface->switchField() => $included]);
    }

    #[\NoDiscard]
    public function changedBy(InclusionChange $change): self
    {
        $switches = $this;
        foreach (InclusionSurface::cases() as $surface) {
            $switches = $switches->with($surface, $change->switchFor($surface) ?? $this->isIncludedIn($surface));
        }

        return $switches;
    }
}
