<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\TrendingWindow;

/** The one place a window maps to the trending_story_entry flag that places a member in it (#2128). */
final class TrendingPlacementSql
{
    public static function placedIn(TrendingWindow $window, string $member): string
    {
        return match ($window) {
            TrendingWindow::Day => \sprintf('%s.in_day = 1', $member),
            TrendingWindow::Week => \sprintf('%s.in_week = 1', $member),
        };
    }
}
