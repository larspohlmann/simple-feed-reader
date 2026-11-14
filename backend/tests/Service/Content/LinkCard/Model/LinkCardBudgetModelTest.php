<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard\Model;

use App\Entity\ShareEnrichment;
use App\Service\LinkCard\Model\LinkCardBudgetModel;
use App\Service\Reader\PageHeadFetcher;
use PHPUnit\Framework\TestCase;

final class LinkCardBudgetModelTest extends TestCase
{
    public function testItsLengthIsTheMostAFetchMayTakePerFetch(): void
    {
        self::assertSame(5 * PageHeadFetcher::MAX_DURATION_SECONDS, LinkCardBudgetModel::fetches(5)->seconds());
        self::assertSame(5, LinkCardBudgetModel::fetches(5)->fetches);
    }

    public function testFurtherFetchesFitOnlyWhileTheyEndWithinTheBudget(): void
    {
        $budget = LinkCardBudgetModel::fetches(3);
        $oneFetch = PageHeadFetcher::MAX_DURATION_SECONDS;

        self::assertTrue($budget->fitsAfter(0.0, 3));
        self::assertFalse($budget->fitsAfter(0.0, 4));
        self::assertTrue($budget->fitsAfter($oneFetch, 2));
        self::assertFalse($budget->fitsAfter($oneFetch + 0.001, 2));
        self::assertTrue($budget->fitsAfter(2.5 * $oneFetch, 0));
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

    public function testABudgetThatWouldOutlastTheClaimLeaseIsRefused(): void
    {
        $longestAccepted = LinkCardBudgetModel::fetches(LinkCardBudgetModel::largestPass())->seconds();
        self::assertLessThan(ShareEnrichment::CLAIM_LEASE_SECONDS, $longestAccepted);

        $this->expectException(\InvalidArgumentException::class);

        LinkCardBudgetModel::fetches(LinkCardBudgetModel::largestPass() + 1);
    }

    public function testTheLargestPassIsSevenFetchesUnderTheOneMinuteLease(): void
    {
        self::assertSame(7, LinkCardBudgetModel::largestPass());
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
