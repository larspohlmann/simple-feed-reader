<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use App\Http\ForYouSweepReportJson;
use App\Service\Recommendation\Run\Model\ForYouSweepReportModel;

final readonly class ForYouSweepJson implements SweepReportJsonInterface
{
    /** @return array<string, int>|null */
    public function figures(object $report): ?array
    {
        if (!$report instanceof ForYouSweepReportModel) {
            return null;
        }

        return ForYouSweepReportJson::report($report);
    }
}
