<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\MaintenanceTickJson;
use App\Service\Image\ImageVerificationReport;
use App\Service\Logging\Loki\LokiSpoolReport;
use App\Service\Mail\Digest\DigestSweepReport;
use App\Service\Maintenance\MaintenanceSweeps;
use App\Service\Maintenance\MaintenanceTickReport;
use App\Service\Recommendation\ForYouSweepReport;
use App\Service\Refresh\RefreshReport;
use App\Service\Search\Membership\SavedSearchMembershipSweepReport;
use PHPUnit\Framework\TestCase;

final class MaintenanceTickJsonTest extends TestCase
{
    public function testACompletedTickSendsEveryHalfUnderItsKey(): void
    {
        $report = new MaintenanceTickReport(
            RefreshReport::finished(9, 1, 2, 3, 4, 5, 0, 7),
            new MaintenanceSweeps(
                new ForYouSweepReport(1, 2, 3),
                new DigestSweepReport(4, 5, 6),
                new ImageVerificationReport(7, 8, 9, 10),
                new SavedSearchMembershipSweepReport(11, 12, 13, true),
            ),
            new LokiSpoolReport(14, 15),
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
                'recommendations' => ['startedRuns' => 1, 'advancedRuns' => 2, 'activeRuns' => 3],
                'digests' => ['considered' => 4, 'sent' => 5, 'skippedEmpty' => 6],
                'imageVerification' => ['measured' => 7, 'kept' => 8, 'dropped' => 9, 'retried' => 10],
                'savedSearchMemberships' => [
                    'searchesSwept' => 11,
                    'entriesScanned' => 12,
                    'matchesInserted' => 13,
                    'caughtUp' => true,
                ],
                'logShipping' => ['shipped' => 14, 'failed' => 15],
            ],
            MaintenanceTickJson::report($report),
        );
    }

    public function testSweepsSkippedAfterAnAbortedRefreshSayWhy(): void
    {
        $report = new MaintenanceTickReport(
            RefreshReport::aborted(5, 1, 1, 1, 0, 2),
            MaintenanceSweeps::skippedAfterAbortedRefresh(),
            new LokiSpoolReport(0, 0),
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
                'recommendations' => [
                    'startedRuns' => 0,
                    'advancedRuns' => 0,
                    'activeRuns' => 0,
                    'skipped' => $reason,
                ],
                'digests' => ['considered' => 0, 'sent' => 0, 'skippedEmpty' => 0, 'skipped' => $reason],
                'imageVerification' => [
                    'measured' => 0,
                    'kept' => 0,
                    'dropped' => 0,
                    'retried' => 0,
                    'skipped' => $reason,
                ],
                'savedSearchMemberships' => [
                    'searchesSwept' => 0,
                    'entriesScanned' => 0,
                    'matchesInserted' => 0,
                    'caughtUp' => false,
                    'skipped' => $reason,
                ],
                'logShipping' => ['shipped' => 0, 'failed' => 0],
            ],
            MaintenanceTickJson::report($report),
        );
    }
}
