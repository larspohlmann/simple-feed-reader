<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\RecommendationWinnerRanker;
use PHPUnit\Framework\TestCase;

final class RecommendationWinnerRankerTest extends TestCase
{
    private RecommendationWinnerRanker $ranker;

    protected function setUp(): void
    {
        $this->ranker = new RecommendationWinnerRanker();
    }

    public function testRankedFlattensAllBatchesAndSortsByScoreDescending(): void
    {
        $ranked = $this->ranker->ranked([
            [['id' => 1, 'score' => 40, 'reason' => 'a'], ['id' => 2, 'score' => 90, 'reason' => 'b']],
            [['id' => 3, 'score' => 70, 'reason' => 'c']],
        ]);

        self::assertSame([2, 3, 1], array_column($ranked, 'id'));
        self::assertSame([90, 70, 40], array_column($ranked, 'score'));
        self::assertSame(['b', 'c', 'a'], array_column($ranked, 'reason'));
    }

    public function testTiedScoresKeepBatchOrderWhichIsSnapshotRecencyOrder(): void
    {
        $ranked = $this->ranker->ranked([
            [['id' => 1, 'score' => 50, 'reason' => 'first batch, first line']],
            [['id' => 2, 'score' => 50, 'reason' => 'second batch']],
        ]);

        self::assertSame([1, 2], array_column($ranked, 'id'));
    }

    public function testAWinnerRecordedWithoutAScoreReadsAsZeroAndSortsLast(): void
    {
        $ranked = $this->ranker->ranked([
            [['id' => 1, 'reason' => 'legacy row'], ['id' => 2, 'score' => 10, 'reason' => 'scored']],
        ]);

        self::assertSame([2, 1], array_column($ranked, 'id'));
        self::assertSame(0, $ranked[1]['score']);
    }

    public function testEmptyBatchesRankToAnEmptyPool(): void
    {
        self::assertSame([], $this->ranker->ranked([[], []]));
    }

    public function testCutForDedupKeepsTwiceThePicksLimit(): void
    {
        $ranked = [];
        for ($id = 1; $id <= 10; ++$id) {
            $ranked[] = ['id' => $id, 'score' => 100 - $id, 'reason' => 'r'];
        }

        $cut = $this->ranker->cutForDedup($ranked, 3);

        self::assertSame([1, 2, 3, 4, 5, 6], array_column($cut, 'id'));
    }

    public function testCutForDedupLeavesAShortPoolUntouched(): void
    {
        $ranked = [['id' => 1, 'score' => 5, 'reason' => 'r']];

        self::assertSame($ranked, $this->ranker->cutForDedup($ranked, 100));
    }
}
