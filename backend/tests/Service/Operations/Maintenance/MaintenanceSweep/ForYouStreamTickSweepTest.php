<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Curation\Recommendation\Stream\Model\ForYouStreamSweepReportModel;
use App\Service\Operations\Maintenance\MaintenanceSweep\ForYouStreamTickSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsForYouStreamBacklog;
use App\Tests\Support\TickingClock;

final class ForYouStreamTickSweepTest extends DbTestCase
{
    use SeedsForYouStreamBacklog;

    public function testTheSweepGetsAtMostTenSecondsOfTheTickBudget(): void
    {
        $this->seedOneEntryBacklog('tick-budget-a@example.test');
        $withinBudget = $this->runAfterStepSeconds(9);
        $this->seedOneEntryBacklog('tick-budget-b@example.test');
        $atBudget = $this->runAfterStepSeconds(10);

        self::assertSame(1, $withinBudget->entriesScanned);
        self::assertSame(0, $atBudget->entriesScanned);
    }

    private function runAfterStepSeconds(int $step): ForYouStreamSweepReportModel
    {
        $clock = new TickingClock(new \DateTimeImmutable('2026-11-27 10:00:00'), $step);

        return (new ForYouStreamTickSweep($this->sweepOnClock($clock), $clock))
            ->run(new \DateTimeImmutable('2030-01-01 00:00:00'));
    }
}
