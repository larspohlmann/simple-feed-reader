<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use App\Service\Curation\Recommendation\Stream\Model\InterestTermSweepReportModel;

final readonly class InterestTermSweepJson implements SweepReportJsonInterface
{
    /** @return array{due: int, stored: int, failed: int, raritiesRefreshed: int}|null */
    public function figures(object $report): ?array
    {
        if (!$report instanceof InterestTermSweepReportModel) {
            return null;
        }

        return $report->toLogContext();
    }
}
