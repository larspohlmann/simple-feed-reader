<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use App\Service\Curation\Recommendation\Stream\Model\ForYouStreamSweepReportModel;

final readonly class ForYouStreamSweepJson implements SweepReportJsonInterface
{
    /** @return array{usersSwept: int, entriesScanned: int, matchesInserted: int, caughtUp: bool}|null */
    public function figures(object $report): ?array
    {
        if (!$report instanceof ForYouStreamSweepReportModel) {
            return null;
        }

        return $report->toLogContext();
    }
}
