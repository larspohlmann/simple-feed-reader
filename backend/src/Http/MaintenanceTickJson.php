<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Logging\Loki\Model\LokiSpoolReportModel;
use App\Service\Maintenance\Model\MaintenanceSweepsModel;
use App\Service\Maintenance\Model\MaintenanceTickReportModel;

/** The /maintenance/tick response: every half under a stable key; a skipped sweep says why. */
final class MaintenanceTickJson
{
    public const string SKIPPED_REASON = 'refresh aborted: the shared EntityManager is unusable this tick';

    /** @return array<string, array<string, int|bool|string>> */
    public static function report(MaintenanceTickReportModel $report): array
    {
        return ['refresh' => RefreshReportJson::report($report->refresh)]
            + self::sweeps($report->sweeps)
            + ['logShipping' => self::logShipping($report->logShipping)];
    }

    /** @return array<string, array<string, int|bool|string>> */
    private static function sweeps(MaintenanceSweepsModel $sweeps): array
    {
        $json = [];
        foreach ($sweeps->reports as $name => $report) {
            $json[$name] = $sweeps->skipped
                ? $report->outcome + ['skipped' => self::SKIPPED_REASON]
                : $report->outcome;
        }

        return $json;
    }

    /** @return array{shipped: int, failed: int} */
    private static function logShipping(LokiSpoolReportModel $report): array
    {
        return ['shipped' => $report->shipped, 'failed' => $report->failed];
    }
}
