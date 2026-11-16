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

    /** @return list<int> one 0 or 1 per window, in case order: the order the member columns list the flags */
    public static function placementIn(TrendingWindow $window): array
    {
        return array_map(
            static fn (TrendingWindow $each): int => $each === $window ? 1 : 0,
            TrendingWindow::cases(),
        );
    }

    public static function flagOf(TrendingWindow $window): string
    {
        return match ($window) {
            TrendingWindow::Day => 'in_day',
            TrendingWindow::Week => 'in_week',
        };
    }
}
