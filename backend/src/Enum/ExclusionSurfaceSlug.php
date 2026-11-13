<?php

declare(strict_types=1);

namespace App\Enum;

enum ExclusionSurfaceSlug: string
{
    case AllItems = 'all-items';
    case ForYou = 'for-you';
    case Trending = 'trending';

    public function surface(): NetworkExclusionSurface
    {
        return match ($this) {
            self::AllItems => NetworkExclusionSurface::AllItems,
            self::ForYou => NetworkExclusionSurface::ForYou,
            self::Trending => NetworkExclusionSurface::Trending,
        };
    }
}
