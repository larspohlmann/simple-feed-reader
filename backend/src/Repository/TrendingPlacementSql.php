<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\TrendingWindow;

/** The one place a window maps to the trending_story_entry flag that places a member in it (#2128). */
final class TrendingPlacementSql
{
    public static function placedIn(TrendingWindow $window, string $member): string
    {
        return \sprintf('%s.%s = 1', $member, self::flagOf($window));
    }

    public static function flagOf(TrendingWindow $window): string
    {
        return match ($window) {
            TrendingWindow::Day => 'in_day',
            TrendingWindow::Week => 'in_week',
        };
    }
}
