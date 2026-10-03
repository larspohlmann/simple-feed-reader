<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Recommendation\Run\Model\ForYouSweepReportModel;

final class ForYouSweepReportJson
{
    /**
     * @return array{
     *     startedRuns: int,
     *     advancedRuns: int,
     *     activeRuns: int,
     *     startedProfileRuns: int,
     *     advancedProfileRuns: int,
     *     activeProfileRuns: int,
     * }
     */
    public static function report(ForYouSweepReportModel $report): array
    {
        return [
            'startedRuns' => $report->startedRuns,
            'advancedRuns' => $report->advancedRuns,
            'activeRuns' => $report->activeRuns,
            'startedProfileRuns' => $report->startedProfileRuns,
            'advancedProfileRuns' => $report->advancedProfileRuns,
            'activeProfileRuns' => $report->activeProfileRuns,
        ];
    }
}
