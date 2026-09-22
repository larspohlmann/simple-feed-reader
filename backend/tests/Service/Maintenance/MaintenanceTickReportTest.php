<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance;

use App\Service\Maintenance\MaintenanceTickReport;
use PHPUnit\Framework\TestCase;

final class MaintenanceTickReportTest extends TestCase
{
    public function testExposesBothHalvesUnderStableKeys(): void
    {
        $report = new MaintenanceTickReport(
            ['status' => 'completed', 'remaining' => 0],
            ['startedRuns' => 1, 'advancedRuns' => 2, 'activeRuns' => 3],
            ['considered' => 4, 'sent' => 5, 'skippedEmpty' => 6],
            ['measured' => 7, 'kept' => 8, 'dropped' => 9, 'retried' => 10],
            ['searchesSwept' => 11, 'entriesScanned' => 12, 'matchesInserted' => 13, 'caughtUp' => true],
            ['shipped' => 1, 'failed' => 0],
        );

        self::assertSame(
            [
                'refresh' => ['status' => 'completed', 'remaining' => 0],
                'recommendations' => ['startedRuns' => 1, 'advancedRuns' => 2, 'activeRuns' => 3],
                'digests' => ['considered' => 4, 'sent' => 5, 'skippedEmpty' => 6],
                'imageVerification' => ['measured' => 7, 'kept' => 8, 'dropped' => 9, 'retried' => 10],
                'savedSearchMemberships' => [
                    'searchesSwept' => 11,
                    'entriesScanned' => 12,
                    'matchesInserted' => 13,
                    'caughtUp' => true,
                ],
                'logShipping' => ['shipped' => 1, 'failed' => 0],
            ],
            $report->toArray(),
        );
    }
}
