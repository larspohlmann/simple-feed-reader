<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Model;

use App\Service\Refresh\Model\RefreshReportModel;
use PHPUnit\Framework\TestCase;

final class RefreshReportModelTest extends TestCase
{
    public function testAbortedReportIsAborted(): void
    {
        $report = RefreshReportModel::aborted(5, 1, 1, 1, 0, 2);

        self::assertTrue($report->isAborted());
    }

    public function testBusyReportIsNotAborted(): void
    {
        $report = RefreshReportModel::busy();

        self::assertFalse($report->isAborted());
    }

    public function testFinishedReportIsNotAborted(): void
    {
        $report = RefreshReportModel::finished(5, 5, 0, 0, 0, 0, 0, 0);

        self::assertFalse($report->isAborted());
    }
}
