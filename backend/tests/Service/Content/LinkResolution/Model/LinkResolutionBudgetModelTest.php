<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\LinkResolution\Model;

use App\Service\Content\LinkResolution\Model\LinkResolutionBudgetModel;
use PHPUnit\Framework\TestCase;

final class LinkResolutionBudgetModelTest extends TestCase
{
    public function testAFreshPassFitsALink(): void
    {
        self::assertTrue(LinkResolutionBudgetModel::links(5)->fitsAfter(0.0));
    }

    public function testTheLastWorstCaseMustFitWhole(): void
    {
        self::assertTrue(LinkResolutionBudgetModel::links(2)->fitsAfter(6.0));
        self::assertFalse(LinkResolutionBudgetModel::links(1)->fitsAfter(0.1));
    }

    public function testADeadlineFitsItsWholeLinks(): void
    {
        $now = new \DateTimeImmutable('2026-11-15 12:00:00');

        self::assertSame(2, LinkResolutionBudgetModel::fittingBefore($now->modify('+13 seconds'), $now, 5)->links);
        self::assertSame(2, LinkResolutionBudgetModel::fittingBefore($now->modify('+12 seconds'), $now, 5)->links);
        self::assertSame(2, LinkResolutionBudgetModel::fittingBefore($now->modify('+17 seconds'), $now, 5)->links);
        self::assertSame(5, LinkResolutionBudgetModel::fittingBefore($now->modify('+1 hour'), $now, 5)->links);
        self::assertTrue(LinkResolutionBudgetModel::fittingBefore($now->modify('+1 second'), $now, 5)->isEmpty());
        self::assertTrue(LinkResolutionBudgetModel::fittingBefore($now->modify('-1 minute'), $now, 5)->isEmpty());
    }

    public function testAPassFollowsFiveLinks(): void
    {
        self::assertSame(5, LinkResolutionBudgetModel::perPass()->links);
    }

    public function testABudgetIsEmptyOnlyAtZero(): void
    {
        self::assertTrue(LinkResolutionBudgetModel::links(0)->isEmpty());
        self::assertFalse(LinkResolutionBudgetModel::links(1)->isEmpty());
    }

    public function testANegativeBudgetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LinkResolutionBudgetModel::links(-1);
    }
}
