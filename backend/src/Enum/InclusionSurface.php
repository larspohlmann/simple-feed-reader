<?php

declare(strict_types=1);

namespace App\Enum;

enum InclusionSurface
{
    case AllItems;
    case ForYou;
    case SavedSearches;
    case Trending;

    public function switchField(): string
    {
        return match ($this) {
            self::AllItems => 'allItems',
            self::ForYou => 'forYou',
            self::SavedSearches => 'savedSearches',
            self::Trending => 'trending',
        };
    }
}
