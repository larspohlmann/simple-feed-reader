<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership\Model;

use App\Service\Search\Membership\Model\SweepBudgetModel;
use PHPUnit\Framework\TestCase;

final class SweepBudgetModelTest extends TestCase
{
    public function testTheDeadlineIsTheStartPlusTheBudget(): void
    {
        $start = new \DateTimeImmutable('2026-09-22T10:00:00');

        self::assertEquals(
            new \DateTimeImmutable('2026-09-22T10:00:10'),
            SweepBudgetModel::seconds(10)->deadlineFrom($start),
        );
    }

    public function testTheRemainingBudgetIsCappedAndNeverNegative(): void
    {
        $now = new \DateTimeImmutable('2026-09-22T10:00:00');

        $plenty = SweepBudgetModel::remainingUntil($now->modify('+25 seconds'), $now, 10);
        $little = SweepBudgetModel::remainingUntil($now->modify('+4 seconds'), $now, 10);
        $spent = SweepBudgetModel::remainingUntil($now->modify('-3 seconds'), $now, 10);

        self::assertEquals($now->modify('+10 seconds'), $plenty->deadlineFrom($now));
        self::assertEquals($now->modify('+4 seconds'), $little->deadlineFrom($now));
        self::assertEquals($now, $spent->deadlineFrom($now));
    }

    public function testANegativeBudgetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SweepBudgetModel::seconds(-1);
    }

    public function testAZeroBudgetIsAllowed(): void
    {
        $start = new \DateTimeImmutable('2026-09-22T10:00:00');

        self::assertEquals($start, SweepBudgetModel::seconds(0)->deadlineFrom($start));
    }
}
