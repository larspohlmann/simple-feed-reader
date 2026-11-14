<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Service\Social\Model\SelfThreadBudgetModel;
use PHPUnit\Framework\TestCase;

final class SelfThreadBudgetModelTest extends TestCase
{
    public function testItCapsTheThreadsOnePassReads(): void
    {
        self::assertSame(1, SelfThreadBudgetModel::threads(1)->threads);
    }

    public function testAThreadStartsOnlyWhileItsLongestCallEndsTenSecondsBeforeTheLeaseRunsOut(): void
    {
        $budget = SelfThreadBudgetModel::threads(25);

        self::assertTrue($budget->fitsAfter(0.0));
        self::assertTrue($budget->fitsAfter(30.0));
        self::assertFalse($budget->fitsAfter(30.001));
    }

    public function testABudgetOfNoThreadIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SelfThreadBudgetModel::threads(0);
    }
}
