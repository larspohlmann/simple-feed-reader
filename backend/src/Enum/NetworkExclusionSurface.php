<?php

declare(strict_types=1);

namespace App\Enum;

enum NetworkExclusionSurface: string
{
    case AllItems = 'all_items';
    case ForYou = 'for_you';
    case Trending = 'trending';
}
