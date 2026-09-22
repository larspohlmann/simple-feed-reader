<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership;

use App\Service\Search\Membership\SweepTally;
use PHPUnit\Framework\TestCase;

final class SavedSearchMembershipSweepReportTest extends TestCase
{
    public function testTheTallyBecomesAReportWithStableKeys(): void
    {
        $tally = new SweepTally();
        $tally->searchesSwept = 2;
        $tally->entriesScanned = 1000;
        $tally->matchesInserted = 7;

        self::assertSame(
            ['searchesSwept' => 2, 'entriesScanned' => 1000, 'matchesInserted' => 7, 'caughtUp' => false],
            $tally->toReport(false)->toArray(),
        );
    }
}
