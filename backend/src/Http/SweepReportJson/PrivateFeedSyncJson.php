<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use App\Service\Social\Social\Model\PrivateFeedSyncReportModel;

final readonly class PrivateFeedSyncJson implements SweepReportJsonInterface
{
    /** @return array{accountsChecked: int, accountsSkipped: int, added: int, removed: int}|null */
    public function figures(object $report): ?array
    {
        if (!$report instanceof PrivateFeedSyncReportModel) {
            return null;
        }

        return [
            'accountsChecked' => $report->checked,
            'accountsSkipped' => $report->skipped,
            'added' => $report->added,
            'removed' => $report->removed,
        ];
    }
}
