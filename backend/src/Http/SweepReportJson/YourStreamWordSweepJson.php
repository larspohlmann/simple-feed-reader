<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use App\Service\Curation\YourStream\Model\YourStreamWordSweepReportModel;

final readonly class YourStreamWordSweepJson implements SweepReportJsonInterface
{
    /** @return array{raritiesRefreshed: int}|null */
    public function figures(object $report): ?array
    {
        if (!$report instanceof YourStreamWordSweepReportModel) {
            return null;
        }

        return $report->toLogContext();
    }
}
