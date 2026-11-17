<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Social\Social\Model\ProfileCallBudgetModel;
use App\Service\Social\Social\Model\ProfileImageSweepReportModel;
use App\Service\Social\Social\ProfileImageSweep;
use App\Service\Social\Social\ProfilePictureSource\ProfilePictureSourceInterface;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

final class ProfileImageSweepTest extends DbTestCase
{
    public function testASecondSourceGetsOnlyTheCallsTheFirstLeft(): void
    {
        $budgets = new \ArrayObject();
        $report = $this->sweepOver([$this->source(1, $budgets), $this->source(1, $budgets)], 2);

        self::assertSame([2, 1], $budgets->getArrayCopy());
        self::assertSame(2, $report->checked);
        self::assertSame(2, $report->calls);
    }

    public function testAnExhaustedBudgetSkipsTheRemainingSources(): void
    {
        $budgets = new \ArrayObject();
        $this->sweepOver([$this->source(2, $budgets), $this->source(0, $budgets)], 2);

        self::assertSame([2], $budgets->getArrayCopy());
    }

    public function testAnEmptyBudgetAsksNoSource(): void
    {
        $budgets = new \ArrayObject();
        $this->sweepOver([$this->source(0, $budgets)], 0);

        self::assertSame([], $budgets->getArrayCopy());
    }

    /** @param list<ProfilePictureSourceInterface> $sources */
    private function sweepOver(array $sources, int $calls): ProfileImageSweepReportModel
    {
        $sweep = new ProfileImageSweep(
            $sources,
            $this->entityManager,
            new NaiveUtcClock(new MockClock('2026-10-12 12:00:00', 'UTC')),
        );

        return $sweep->sweep(ProfileCallBudgetModel::calls($calls));
    }

    /** @param \ArrayObject<int, int> $budgets */
    private function source(int $spends, \ArrayObject $budgets): ProfilePictureSourceInterface
    {
        return new class ($spends, $budgets) implements ProfilePictureSourceInterface {
            /** @param \ArrayObject<int, int> $budgets */
            public function __construct(private readonly int $spends, private readonly \ArrayObject $budgets)
            {
            }

            public function refresh(
                ProfileCallBudgetModel $budget,
                \DateTimeImmutable $now,
            ): ProfileImageSweepReportModel {
                $this->budgets->append($budget->calls);

                return new ProfileImageSweepReportModel(1, 1, $this->spends);
            }
        };
    }
}
