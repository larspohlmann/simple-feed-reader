<?php

declare(strict_types=1);

namespace App\Tests\Service\Transcript\Model;

use App\Service\Transcript\Model\TranscriptBudgetModel;
use PHPUnit\Framework\TestCase;

final class TranscriptBudgetModelTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-27 12:00:00');
    }

    public function testANegativeBudgetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TranscriptBudgetModel::fetches(-1);
    }

    public function testOnlyWholeFetchesFitBeforeTheDeadline(): void
    {
        $budget = TranscriptBudgetModel::fittingBefore($this->now->modify('+9 seconds'), $this->now, 5);

        self::assertSame(2, $budget->fetches);
    }

    public function testLessThanOneFetchLeavesAnEmptyBudget(): void
    {
        $budget = TranscriptBudgetModel::fittingBefore($this->now->modify('+3 seconds'), $this->now, 5);

        self::assertTrue($budget->isEmpty());
    }

    public function testThePassCapLimitsAWideWindow(): void
    {
        $budget = TranscriptBudgetModel::fittingBefore($this->now->modify('+60 seconds'), $this->now, 2);

        self::assertSame(2, $budget->fetches);
    }

    public function testADeadlineInThePastLeavesAnEmptyBudget(): void
    {
        $budget = TranscriptBudgetModel::fittingBefore($this->now->modify('-10 seconds'), $this->now, 2);

        self::assertTrue($budget->isEmpty());
    }
}
