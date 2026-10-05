<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Model;

use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use PHPUnit\Framework\TestCase;

final class ScoringBudgetModelTest extends TestCase
{
    public function testTheItemsGetTheWindowLessTheFramingAndTheState(): void
    {
        self::assertSame(6_800, (new ScoringBudgetModel(9_000, 7, 1_500, 700))->itemTokens());
    }
}
