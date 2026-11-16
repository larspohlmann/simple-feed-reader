<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use App\Service\Social\Notification\Model\NotificationPollReportModel;

final readonly class NotificationPollJson implements SweepReportJsonInterface
{
    /** @return array{accountsChecked: int, failed: int, newRows: int, pruned: int}|null */
    public function figures(object $report): ?array
    {
        if (!$report instanceof NotificationPollReportModel) {
            return null;
        }

        return [
            'accountsChecked' => $report->accounts,
            'failed' => $report->failed,
            'newRows' => $report->newRows,
            'pruned' => $report->pruned,
        ];
    }
}
