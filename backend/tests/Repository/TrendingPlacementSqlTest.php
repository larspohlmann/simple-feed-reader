<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Enum\TrendingWindow;
use App\Repository\TrendingPlacementSql;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;

final class TrendingPlacementSqlTest extends DbTestCase
{
    public function testPlacedInFiltersByTheWindowsRows(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $feed = $fixtures->feed('placed');
        $day = $fixtures->entry($feed, 'Day', '2031-05-14 08:00:00');
        $week = $fixtures->entry($feed, 'Week', '2031-05-14 08:00:00');
        $elsewhere = $fixtures->entry($feed, 'Elsewhere', '2031-05-14 08:00:00');
        $story = $fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $day);
        $fixtures->join($story, '2031-05-14 10:00:00', $week, $elsewhere);
        $fixtures->place(TrendingWindow::Week, $story, $week);
        $fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $elsewhere);

        self::assertSame(
            [[$day->requireId()], [$week->requireId()]],
            [
                $this->placedOf($story->requireId(), TrendingWindow::Day),
                $this->placedOf($story->requireId(), TrendingWindow::Week),
            ],
        );
    }

    /** @return list<int> */
    private function placedOf(int $storyId, TrendingWindow $window): array
    {
        /** @var list<int|string> $entryIds */
        $entryIds = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT member.entry_id FROM trending_story_entry member WHERE member.story_id = ? AND '
            . TrendingPlacementSql::buildPlacedCondition($window, 'member') . ' ORDER BY member.entry_id',
            [$storyId],
        );

        return array_map(intval(...), $entryIds);
    }
}
