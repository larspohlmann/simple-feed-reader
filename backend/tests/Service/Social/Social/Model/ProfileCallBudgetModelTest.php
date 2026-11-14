<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Service\Social\Model\ProfileCallBudgetModel;
use PHPUnit\Framework\TestCase;

final class ProfileCallBudgetModelTest extends TestCase
{
    private const string NOW = '2026-10-12T12:00:00Z';

    public function testOnlyWholeCallsThatEndBeforeTheDeadlineFit(): void
    {
        self::assertSame(1, $this->fittingWithin(39, 5)->calls);
        self::assertSame(2, $this->fittingWithin(40, 5)->calls);
    }

    public function testTheMaximumCapsTheCalls(): void
    {
        self::assertSame(1, $this->fittingWithin(100, 1)->calls);
    }

    public function testAPassedDeadlineLeavesAnEmptyBudget(): void
    {
        self::assertTrue($this->fittingWithin(-5, 3)->isEmpty());
        self::assertTrue($this->fittingWithin(19, 3)->isEmpty());
    }

    public function testABudgetCannotBeNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProfileCallBudgetModel::calls(-1);
    }

    private function fittingWithin(int $seconds, int $maxCalls): ProfileCallBudgetModel
    {
        $now = new \DateTimeImmutable(self::NOW);

        return ProfileCallBudgetModel::fittingBefore($now->modify(sprintf('%+d seconds', $seconds)), $now, $maxCalls);
    }
}
