<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard\Model;

use App\Service\LinkCard\Model\LinkCardBudgetModel;
use PHPUnit\Framework\TestCase;

final class LinkCardBudgetModelTest extends TestCase
{
    public function testItsLengthIsEightSecondsPerFetch(): void
    {
        self::assertSame(40.0, LinkCardBudgetModel::fetches(5)->seconds());
        self::assertSame(5, LinkCardBudgetModel::fetches(5)->fetches);
    }

    public function testNoFetchIsAnEmptyBudget(): void
    {
        self::assertTrue(LinkCardBudgetModel::fetches(0)->isEmpty());
        self::assertFalse(LinkCardBudgetModel::fetches(1)->isEmpty());
    }

    public function testANegativeCountIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LinkCardBudgetModel::fetches(-1);
    }

    public function testFitsTheWholeFetchesLeftBeforeADeadlineUpToAMaximum(): void
    {
        $now = new \DateTimeImmutable('2026-10-11 12:00:00');

        self::assertSame(2, LinkCardBudgetModel::fittingBefore($now->modify('+20 seconds'), $now, 2)->fetches);
        self::assertSame(1, LinkCardBudgetModel::fittingBefore($now->modify('+15 seconds'), $now, 2)->fetches);
        self::assertSame(1, LinkCardBudgetModel::fittingBefore($now->modify('+8 seconds'), $now, 2)->fetches);
        self::assertTrue(LinkCardBudgetModel::fittingBefore($now->modify('+7 seconds'), $now, 2)->isEmpty());
        self::assertTrue(LinkCardBudgetModel::fittingBefore($now->modify('-3 seconds'), $now, 2)->isEmpty());
    }
}
