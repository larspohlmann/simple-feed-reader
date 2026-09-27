<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Recommendation\ForYouSweepReport;

final class ForYouSweepReportJson
{
    /** @return array{startedRuns: int, advancedRuns: int, activeRuns: int} */
    public static function report(ForYouSweepReport $report): array
    {
        return [
            'startedRuns' => $report->startedRuns,
            'advancedRuns' => $report->advancedRuns,
            'activeRuns' => $report->activeRuns,
        ];
    }
}
