<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\RefreshReportJson;
use App\Service\Refresh\Model\RefreshReportModel;
use PHPUnit\Framework\TestCase;

final class RefreshReportJsonTest extends TestCase
{
    public function testAFinishedRunSendsEveryCounterUnderItsOwnKey(): void
    {
        $report = RefreshReportModel::finished(
            total: 9,
            fetched: 1,
            notModified: 2,
            failed: 3,
            throttled: 4,
            skippedForBudget: 5,
            remaining: 6,
            pruned: 7,
        );

        self::assertSame(
            [
                'status' => 'partial',
                'total' => 9,
                'fetched' => 1,
                'notModified' => 2,
                'failed' => 3,
                'throttled' => 4,
                'skippedForBudget' => 5,
                'remaining' => 6,
                'pruned' => 7,
            ],
            RefreshReportJson::report($report),
        );
    }

    public function testABusyRunSendsItsStatusAndZeroes(): void
    {
        self::assertSame(
            [
                'status' => 'busy',
                'total' => 0,
                'fetched' => 0,
                'notModified' => 0,
                'failed' => 0,
                'throttled' => 0,
                'skippedForBudget' => 0,
                'remaining' => 0,
                'pruned' => 0,
            ],
            RefreshReportJson::report(RefreshReportModel::busy()),
        );
    }
}
