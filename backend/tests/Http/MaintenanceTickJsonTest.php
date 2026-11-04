<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\MaintenanceTickJson;
use App\Service\Logging\Loki\Model\LokiSpoolReportModel;
use App\Service\Maintenance\Model\MaintenanceSweepsModel;
use App\Service\Maintenance\Model\MaintenanceTickReportModel;
use App\Service\Maintenance\Model\SweepReportModel;
use App\Service\Refresh\Model\RefreshReportModel;
use PHPUnit\Framework\TestCase;

final class MaintenanceTickJsonTest extends TestCase
{
    public function testACompletedTickSendsEachSweepUnderItsNameBetweenRefreshAndLogShipping(): void
    {
        $report = new MaintenanceTickReportModel(
            RefreshReportModel::finished(9, 1, 2, 3, 4, 5, 0, 7),
            MaintenanceSweepsModel::ran([
                'digests' => new SweepReportModel(['considered' => 4, 'sent' => 5]),
                'savedSearchMemberships' => new SweepReportModel(['matchesInserted' => 13, 'caughtUp' => true]),
            ]),
            new LokiSpoolReportModel(14, 15),
        );

        self::assertSame(
            [
                'refresh' => [
                    'status' => 'completed',
                    'total' => 9,
                    'fetched' => 1,
                    'notModified' => 2,
                    'failed' => 3,
                    'throttled' => 4,
                    'skippedForBudget' => 5,
                    'remaining' => 0,
                    'pruned' => 7,
                ],
                'digests' => ['considered' => 4, 'sent' => 5],
                'savedSearchMemberships' => ['matchesInserted' => 13, 'caughtUp' => true],
                'logShipping' => ['shipped' => 14, 'failed' => 15],
            ],
            MaintenanceTickJson::report($report),
        );
    }

    public function testSweepsSkippedAfterAnAbortedRefreshSayWhy(): void
    {
        $report = new MaintenanceTickReportModel(
            RefreshReportModel::aborted(5, 1, 1, 1, 0, 2),
            MaintenanceSweepsModel::skippedAfterAbortedRefresh([
                'linkCards' => new SweepReportModel(['considered' => 0, 'completed' => 0]),
                'trending' => new SweepReportModel(['windowsRebuilt' => 0]),
            ]),
            new LokiSpoolReportModel(0, 0),
        );
        $reason = 'refresh aborted: the shared EntityManager is unusable this tick';

        self::assertSame(
            [
                'refresh' => [
                    'status' => 'aborted',
                    'total' => 5,
                    'fetched' => 1,
                    'notModified' => 1,
                    'failed' => 1,
                    'throttled' => 0,
                    'skippedForBudget' => 0,
                    'remaining' => 2,
                    'pruned' => 0,
                ],
                'linkCards' => ['considered' => 0, 'completed' => 0, 'skipped' => $reason],
                'trending' => ['windowsRebuilt' => 0, 'skipped' => $reason],
                'logShipping' => ['shipped' => 0, 'failed' => 0],
            ],
            MaintenanceTickJson::report($report),
        );
    }
}
