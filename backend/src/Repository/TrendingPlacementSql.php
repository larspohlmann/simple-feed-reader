<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\TrendingWindow;

/** The one place a window's placement rows decide which members of a story it shows (#2128, #2272). */
final class TrendingPlacementSql
{
    public static function buildPlacedCondition(TrendingWindow $window, string $member): string
    {
        return \sprintf(
            'EXISTS (SELECT 1 FROM trending_story_placement placed_%1$s WHERE placed_%1$s.time_window = \'%2$s\''
            . ' AND placed_%1$s.entry_id = %1$s.entry_id AND placed_%1$s.story_id = %1$s.story_id)',
            $member,
            $window->value,
        );
    }
}
