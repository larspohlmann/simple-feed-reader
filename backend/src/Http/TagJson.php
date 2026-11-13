<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Tag;
use App\Enum\InclusionSurface;

final class TagJson
{
    /**
     * @return array{
     *   id: int|null, name: string, color: string|null, icon: string|null, position: int, parentId: int|null,
     *   includeInAllItems: bool, includeInForYou: bool, includeInSavedSearches: bool, includeInTrending: bool
     * }
     */
    public static function one(Tag $tag): array
    {
        return [
            'id' => $tag->getId(),
            'name' => $tag->getName(),
            'color' => $tag->getColor(),
            'icon' => $tag->getIcon(),
            'position' => $tag->getPosition(),
            'parentId' => $tag->getParent()?->getId(),
            'includeInAllItems' => $tag->isIncludedIn(InclusionSurface::AllItems),
            'includeInForYou' => $tag->isIncludedIn(InclusionSurface::ForYou),
            'includeInSavedSearches' => $tag->isIncludedIn(InclusionSurface::SavedSearches),
            'includeInTrending' => $tag->isIncludedIn(InclusionSurface::Trending),
        ];
    }
}
