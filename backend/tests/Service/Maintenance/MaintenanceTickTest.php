<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance;

use App\Service\Maintenance\MaintenanceTick;
use App\Tests\DbTestCase;

final class MaintenanceTickTest extends DbTestCase
{
    public function testRunProducesAReportCarryingBothHalves(): void
    {
        $tick = self::getContainer()->get(MaintenanceTick::class);
        self::assertInstanceOf(MaintenanceTick::class, $tick);

        $report = $tick->run()->toArray();

        // The refresh half always carries a status; the recommendations half
        // always carries the three sweep counts. Exact values are not asserted:
        // the shared test database may hold rows from other classes, so this
        // proves the shape, not a fixed count.
        self::assertArrayHasKey('status', $report['refresh']);
        self::assertIsInt($report['recommendations']['startedRuns']);
        self::assertIsInt($report['recommendations']['advancedRuns']);
        self::assertIsInt($report['recommendations']['activeRuns']);
    }
}
