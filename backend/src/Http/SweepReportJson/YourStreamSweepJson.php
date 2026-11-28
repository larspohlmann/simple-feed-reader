<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use App\Service\Curation\YourStream\Model\YourStreamSweepReportModel;

final readonly class YourStreamSweepJson implements SweepReportJsonInterface
{
    /** @return array{usersSwept: int, entriesScanned: int, matchesInserted: int, caughtUp: bool}|null */
    public function figures(object $report): ?array
    {
        if (!$report instanceof YourStreamSweepReportModel) {
            return null;
        }

        return $report->toLogContext();
    }
}
