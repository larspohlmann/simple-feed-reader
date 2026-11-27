<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Search\Membership\Pass;

use App\Service\Library\Search\Membership\Pass\SweepTally;
use PHPUnit\Framework\TestCase;

final class SweepTallyTest extends TestCase
{
    public function testACaughtUpTallyReportsEveryCounterSummed(): void
    {
        $tally = new SweepTally(new \DateTimeImmutable('2026-11-27 10:00:00'));
        $tally->recordGroup(1);
        $tally->recordGroup(1);
        $tally->recordChunk(500, 3);
        $tally->recordChunk(500, 4);

        $report = $tally->caughtUp();

        self::assertSame(
            [2, 1000, 7, true],
            [$report->membersSwept, $report->entriesScanned, $report->matchesInserted, $report->caughtUp],
        );
    }

    public function testAStoppedShortTallyReportsNotCaughtUp(): void
    {
        $tally = new SweepTally(new \DateTimeImmutable('2026-11-27 10:00:00'));
        $tally->recordChunk(500, 0);

        $report = $tally->stoppedShort();

        self::assertFalse($report->caughtUp);
        self::assertSame(500, $report->entriesScanned);
    }
}
