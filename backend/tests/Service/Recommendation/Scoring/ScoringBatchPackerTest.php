<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use App\Service\Recommendation\Scoring\ScoringBatchPacker;
use PHPUnit\Framework\TestCase;

final class ScoringBatchPackerTest extends TestCase
{
    /**
     * 14 tokens of items, at most 3 a request: 1 and 2 fill it to the token, 3 to 5 stop at the cap with tokens to
     * spare, and 7, over the budget by itself, still gets a request of its own.
     */
    public function testARequestClosesAtTheItemCapOrBeforeTheArticleThatWouldExceedItsTokens(): void
    {
        $tokens = [1 => 5, 2 => 9, 3 => 3, 4 => 8, 5 => 1, 6 => 2, 7 => 20];
        $candidates = array_map(
            static fn (int $entryId): ArticleLineModel
                => new ArticleLineModel($entryId, 'Title', 'Feed', '2026-10-01', null),
            array_keys($tokens),
        );

        $batches = (new ScoringBatchPacker())->pack(
            $candidates,
            new ScoringBudgetModel(20, 3, 4, 2),
            static fn (ArticleLineModel $candidate): int => $tokens[$candidate->entryId]
                ?? throw new \LogicException('Every candidate has its tokens.'),
        );

        self::assertSame([[1, 2], [3, 4, 5], [6], [7]], $batches);
    }
}
