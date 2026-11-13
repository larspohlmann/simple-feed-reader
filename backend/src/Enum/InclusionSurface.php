<?php

declare(strict_types=1);

namespace App\Enum;

enum InclusionSurface: string
{
    case AllItems = 'all-items';
    case ForYou = 'for-you';
    case SavedSearches = 'saved-searches';
    case Trending = 'trending';

    /** Saved searches let networks in through SavedSearchNetworkInclusion instead. */
    public function hasNetworkExclusions(): bool
    {
        return $this !== self::SavedSearches;
    }

    public function switchField(): string
    {
        return match ($this) {
            self::AllItems => 'allItems',
            self::ForYou => 'forYou',
            self::SavedSearches => 'savedSearches',
            self::Trending => 'trending',
        };
    }

    public function switchColumn(): string
    {
        return 'include_in_' . str_replace('-', '_', $this->value);
    }
}
