<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Service\Refresh\RefreshReport;
use PHPUnit\Framework\TestCase;

final class RefreshReportTest extends TestCase
{
    public function testAbortedReportIsAborted(): void
    {
        $report = RefreshReport::aborted(5, 1, 1, 1, 0, 2);

        self::assertTrue($report->isAborted());
    }

    public function testBusyReportIsNotAborted(): void
    {
        $report = RefreshReport::busy();

        self::assertFalse($report->isAborted());
    }

    public function testFinishedReportIsNotAborted(): void
    {
        $report = RefreshReport::finished(5, 5, 0, 0, 0, 0, 0, 0);

        self::assertFalse($report->isAborted());
    }
}
