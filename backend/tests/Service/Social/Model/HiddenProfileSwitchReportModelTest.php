<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Service\Social\Model\HiddenProfileMoveOutcome;
use App\Service\Social\Model\HiddenProfileSwitchReportModel;
use PHPUnit\Framework\TestCase;

final class HiddenProfileSwitchReportModelTest extends TestCase
{
    public function testEachOutcomeButASkipIsCounted(): void
    {
        $report = HiddenProfileSwitchReportModel::none()
            ->counting(HiddenProfileMoveOutcome::Started)
            ->counting(HiddenProfileMoveOutcome::Finished)
            ->counting(HiddenProfileMoveOutcome::Finished)
            ->counting(HiddenProfileMoveOutcome::Waiting)
            ->counting(HiddenProfileMoveOutcome::Skipped);

        self::assertEquals(new HiddenProfileSwitchReportModel(1, 2, 1), $report);
    }

    public function testOnlyARunWithNoMoveUnderWayIsIdle(): void
    {
        self::assertTrue(HiddenProfileSwitchReportModel::none()->counting(HiddenProfileMoveOutcome::Skipped)->isIdle());
        self::assertFalse(new HiddenProfileSwitchReportModel(1, 0)->isIdle());
        self::assertFalse(new HiddenProfileSwitchReportModel(0, 1)->isIdle());
        self::assertFalse(new HiddenProfileSwitchReportModel(0, 0, 1)->isIdle());
        self::assertFalse(new HiddenProfileSwitchReportModel(1, 1)->isIdle());
        self::assertFalse(new HiddenProfileSwitchReportModel(0, 1, 1)->isIdle());
    }
}
