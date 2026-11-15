<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\UrlCleanup\Model;

use App\Service\Operations\UrlCleanup\Model\TrackingUrlCleanupReportModel;
use PHPUnit\Framework\TestCase;

final class TrackingUrlCleanupReportModelTest extends TestCase
{
    public function testTwoReportsAddUpCountByCount(): void
    {
        self::assertEquals(
            new TrackingUrlCleanupReportModel(5, 7, 9),
            new TrackingUrlCleanupReportModel(1, 2, 3)->plus(new TrackingUrlCleanupReportModel(4, 5, 6)),
        );
    }
}
