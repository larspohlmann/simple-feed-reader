<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Model;

use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use PHPUnit\Framework\TestCase;

final class ScoringBudgetModelTest extends TestCase
{
    public function testTheItemsGetTheWindowLessTheFramingAndTheReader(): void
    {
        self::assertSame(6_800, (new ScoringBudgetModel(9_000, 7, 1_500, 700))->itemTokens());
    }

    /** Kev's 8k window: the reader gets 30 %, the framing 2,000, the articles the rest. */
    public function testASmallWindowGivesTheReaderThirtyPercent(): void
    {
        $budget = ScoringBudgetModel::forWindow(8_192, 7);

        self::assertEquals(new ScoringBudgetModel(8_192, 7, 2_457, 2_000), $budget);
        self::assertSame(3_735, $budget->itemTokens());
    }

    public function testTheReadersShareStopsAtTenThousandTokens(): void
    {
        self::assertSame(9_999, ScoringBudgetModel::forWindow(33_333, 7)->readerTokens);
        self::assertSame(10_000, ScoringBudgetModel::forWindow(33_334, 7)->readerTokens);
        self::assertSame(10_000, ScoringBudgetModel::forWindow(65_536, 7)->readerTokens);
    }

    /** A 512-token reranker: a fixed 2,000 would leave the articles a negative budget and send empty documents. */
    public function testATinyWindowStillLeavesTheArticlesAShare(): void
    {
        $budget = ScoringBudgetModel::forWindow(512, 7);

        self::assertEquals(new ScoringBudgetModel(512, 7, 153, 256), $budget);
        self::assertSame(103, $budget->itemTokens());
    }

    public function testTheFramingStaysAtTwoThousandFromAFourThousandTokenWindow(): void
    {
        self::assertSame(1_999, ScoringBudgetModel::forWindow(3_998, 7)->framingTokens);
        self::assertSame(2_000, ScoringBudgetModel::forWindow(4_000, 7)->framingTokens);
        self::assertSame(2_000, ScoringBudgetModel::forWindow(4_096, 7)->framingTokens);
    }
}
