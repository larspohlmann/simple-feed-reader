<?php

declare(strict_types=1);

namespace App\Enum;

enum InclusionSurface
{
    case AllItems;
    case ForYou;
    case SavedSearches;
    case Trending;
}
