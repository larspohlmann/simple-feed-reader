<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Pass;

use App\Service\Fetch\Pass\RedirectBudget;
use PHPUnit\Framework\TestCase;

final class RedirectBudgetTest extends TestCase
{
    public function testTakesRedirectsUntilTheLimitAndThenSpendsNothing(): void
    {
        $budget = new RedirectBudget(2);

        self::assertTrue($budget->takeRedirect());
        self::assertTrue($budget->takeRedirect());
        self::assertFalse($budget->takeRedirect());
        self::assertSame(2, $budget->redirectsTaken());
    }

    public function testABudgetOfNoRedirectsTakesNone(): void
    {
        $budget = new RedirectBudget(0);

        self::assertFalse($budget->takeRedirect());
        self::assertSame(0, $budget->redirectsTaken());
    }

    public function testRunsOutOfTimeOnceItsSecondsAreSpent(): void
    {
        $budget = new RedirectBudget(5, 2.0);
        $budget->spendSeconds(1.5);

        self::assertFalse($budget->isOutOfTime());
        self::assertSame(0.5, $budget->remainingSeconds());

        $budget->spendSeconds(0.5);

        self::assertTrue($budget->isOutOfTime());
    }
}
