<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Service\Social\Model\PrivateFeedSyncBudgetModel;
use PHPUnit\Framework\TestCase;

final class PrivateFeedSyncBudgetModelTest extends TestCase
{
    private const string NOW = '2026-10-17T12:00:00Z';

    public function testOnlyWholeAccountsThatFitBeforeTheDeadlineCount(): void
    {
        self::assertSame(2, $this->fittingWithin(12, 3)->accounts);
        self::assertSame(1, $this->fittingWithin(9, 3)->accounts);
        self::assertSame(1, $this->fittingWithin(5, 3)->accounts);
    }

    public function testTheMaximumCapsTheAccounts(): void
    {
        self::assertSame(1, $this->fittingWithin(30, 1)->accounts);
    }

    public function testAPassedOrTooNearDeadlineLeavesAnEmptyBudget(): void
    {
        self::assertTrue($this->fittingWithin(4, 3)->isEmpty());
        self::assertTrue($this->fittingWithin(-5, 3)->isEmpty());
    }

    public function testABudgetCannotBeNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PrivateFeedSyncBudgetModel::accounts(-1);
    }

    private function fittingWithin(int $seconds, int $maxAccounts): PrivateFeedSyncBudgetModel
    {
        $now = new \DateTimeImmutable(self::NOW);

        return PrivateFeedSyncBudgetModel::fittingBefore(
            $now->modify(sprintf('%+d seconds', $seconds)),
            $now,
            $maxAccounts,
        );
    }
}
