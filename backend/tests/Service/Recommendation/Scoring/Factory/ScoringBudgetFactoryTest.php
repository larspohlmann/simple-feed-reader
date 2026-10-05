<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Factory;

use App\Enum\ScoringProtocol;
use App\Service\Recommendation\Scoring\Factory\ScoringBudgetFactory;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use PHPUnit\Framework\TestCase;

final class ScoringBudgetFactoryTest extends TestCase
{
    /** Jev's budget before #1395: 20,000 tokens of questions, at most 100 of them. */
    public function testSystemOneKeepsTheBudgetJevHad(): void
    {
        $budget = (new ScoringBudgetFactory())->create(ScoringProtocol::SystemOne);

        self::assertEquals(new ScoringBudgetModel(32_000, 100, 10_000, 2_000), $budget);
        self::assertSame(20_000, $budget->itemTokens());
    }
}
