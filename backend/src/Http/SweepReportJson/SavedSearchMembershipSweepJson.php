<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use App\Service\Search\Membership\Model\SavedSearchMembershipSweepReportModel;

final readonly class SavedSearchMembershipSweepJson implements SweepReportJsonInterface
{
    /** @return array{searchesSwept: int, entriesScanned: int, matchesInserted: int, caughtUp: bool}|null */
    public function figures(object $report): ?array
    {
        if (!$report instanceof SavedSearchMembershipSweepReportModel) {
            return null;
        }

        return [
            'searchesSwept' => $report->searchesSwept,
            'entriesScanned' => $report->entriesScanned,
            'matchesInserted' => $report->matchesInserted,
            'caughtUp' => $report->caughtUp,
        ];
    }
}
