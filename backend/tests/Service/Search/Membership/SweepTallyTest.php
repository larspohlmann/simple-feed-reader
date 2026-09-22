<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership;

use App\Service\Search\Membership\SweepTally;
use PHPUnit\Framework\TestCase;

final class SweepTallyTest extends TestCase
{
    public function testACaughtUpTallyBecomesAReportWithStableKeys(): void
    {
        $tally = new SweepTally();
        $tally->searchesSwept = 2;
        $tally->entriesScanned = 1000;
        $tally->matchesInserted = 7;

        self::assertSame(
            ['searchesSwept' => 2, 'entriesScanned' => 1000, 'matchesInserted' => 7, 'caughtUp' => true],
            $tally->caughtUp()->toArray(),
        );
    }

    public function testAStoppedShortTallyReportsNotCaughtUp(): void
    {
        $tally = new SweepTally();
        $tally->entriesScanned = 500;

        $report = $tally->stoppedShort();

        self::assertFalse($report->caughtUp);
        self::assertSame(500, $report->entriesScanned);
    }
}
