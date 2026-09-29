<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Image\Model\ImageVerificationReportModel;
use App\Service\Logging\Loki\Model\LokiSpoolReportModel;
use App\Service\Mail\Digest\Model\DigestSweepReportModel;
use App\Service\Maintenance\Model\MaintenanceSweepsModel;
use App\Service\Maintenance\Model\MaintenanceTickReportModel;
use App\Service\Search\Membership\Model\SavedSearchMembershipSweepReportModel;

/** The /maintenance/tick response: every half under a stable key; a skipped sweep says why. */
final class MaintenanceTickJson
{
    public const string SKIPPED_REASON = 'refresh aborted: the shared EntityManager is unusable this tick';

    /** @return array<string, array<string, int|bool|string>> */
    public static function report(MaintenanceTickReportModel $report): array
    {
        $sweeps = $report->sweeps;

        return [
            'refresh' => RefreshReportJson::report($report->refresh),
            'recommendations' => self::markedIfSkipped(
                $sweeps,
                ForYouSweepReportJson::report($sweeps->recommendations),
            ),
            'digests' => self::markedIfSkipped($sweeps, self::digests($sweeps->digests)),
            'imageVerification' => self::markedIfSkipped(
                $sweeps,
                self::imageVerification($sweeps->imageVerification),
            ),
            'savedSearchMemberships' => self::markedIfSkipped(
                $sweeps,
                self::memberships($sweeps->savedSearchMemberships),
            ),
            'logShipping' => self::logShipping($report->logShipping),
        ];
    }

    /**
     * @param array<string, int|bool> $counts
     *
     * @return array<string, int|bool|string>
     */
    private static function markedIfSkipped(MaintenanceSweepsModel $sweeps, array $counts): array
    {
        if (!$sweeps->skipped) {
            return $counts;
        }

        return $counts + ['skipped' => self::SKIPPED_REASON];
    }

    /** @return array{considered: int, sent: int, skippedEmpty: int} */
    private static function digests(DigestSweepReportModel $report): array
    {
        return ['considered' => $report->considered, 'sent' => $report->sent, 'skippedEmpty' => $report->skippedEmpty];
    }

    /** @return array{measured: int, kept: int, dropped: int, retried: int} */
    private static function imageVerification(ImageVerificationReportModel $report): array
    {
        return [
            'measured' => $report->measured,
            'kept' => $report->kept,
            'dropped' => $report->dropped,
            'retried' => $report->retried,
        ];
    }

    /** @return array{searchesSwept: int, entriesScanned: int, matchesInserted: int, caughtUp: bool} */
    private static function memberships(SavedSearchMembershipSweepReportModel $report): array
    {
        return [
            'searchesSwept' => $report->searchesSwept,
            'entriesScanned' => $report->entriesScanned,
            'matchesInserted' => $report->matchesInserted,
            'caughtUp' => $report->caughtUp,
        ];
    }

    /** @return array{shipped: int, failed: int} */
    private static function logShipping(LokiSpoolReportModel $report): array
    {
        return ['shipped' => $report->shipped, 'failed' => $report->failed];
    }
}
