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

    public function testANegativeBudgetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SweepBudget::seconds(-1);
    }
}
