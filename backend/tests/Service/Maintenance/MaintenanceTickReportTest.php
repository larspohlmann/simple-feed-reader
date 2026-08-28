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
        );

        self::assertSame(
            [
                'refresh' => ['status' => 'completed', 'remaining' => 0],
                'recommendations' => ['startedRuns' => 1, 'advancedRuns' => 2, 'activeRuns' => 3],
                'digests' => ['considered' => 4, 'sent' => 5, 'skippedEmpty' => 6],
            ],
            $report->toArray(),
        );
    }
}
