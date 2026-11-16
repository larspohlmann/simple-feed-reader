<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Enum\TrendingWindow;
use App\Repository\TrendingPlacementSql;
use PHPUnit\Framework\TestCase;

final class TrendingPlacementSqlTest extends TestCase
{
    public function testAPlacementSetsOnlyItsWindowsFlagInColumnOrder(): void
    {
        self::assertSame([1, 0], TrendingPlacementSql::placementIn(TrendingWindow::Day));
        self::assertSame([0, 1], TrendingPlacementSql::placementIn(TrendingWindow::Week));
    }
}
