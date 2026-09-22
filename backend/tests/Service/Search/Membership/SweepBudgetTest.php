<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership;

use App\Service\Search\Membership\SweepBudget;
use PHPUnit\Framework\TestCase;

final class SweepBudgetTest extends TestCase
{
    public function testTheDeadlineIsTheStartPlusTheBudget(): void
    {
        $start = new \DateTimeImmutable('2026-09-22T10:00:00');

        self::assertEquals(
            new \DateTimeImmutable('2026-09-22T10:00:10'),
            SweepBudget::seconds(10)->deadlineFrom($start),
        );
    }

    public function testTheRemainingBudgetIsCappedAndNeverNegative(): void
    {
        $now = new \DateTimeImmutable('2026-09-22T10:00:00');

        $plenty = SweepBudget::remainingUntil($now->modify('+25 seconds'), $now, 10);
        $little = SweepBudget::remainingUntil($now->modify('+4 seconds'), $now, 10);
        $spent = SweepBudget::remainingUntil($now->modify('-3 seconds'), $now, 10);

        self::assertEquals($now->modify('+10 seconds'), $plenty->deadlineFrom($now));
        self::assertEquals($now->modify('+4 seconds'), $little->deadlineFrom($now));
        self::assertEquals($now, $spent->deadlineFrom($now));
    }

    public function testANegativeBudgetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SweepBudget::seconds(-1);
    }

    public function testAZeroBudgetIsAllowed(): void
    {
        $start = new \DateTimeImmutable('2026-09-22T10:00:00');

        self::assertEquals($start, SweepBudget::seconds(0)->deadlineFrom($start));
    }
}
