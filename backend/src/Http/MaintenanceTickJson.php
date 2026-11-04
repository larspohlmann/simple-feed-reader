<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\SweepReportJson\SweepReportJsonInterface;
use App\Service\Logging\Loki\Model\LokiSpoolReportModel;
use App\Service\Maintenance\Model\MaintenanceSweepsModel;
use App\Service\Maintenance\Model\MaintenanceTickReportModel;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** The /maintenance/tick response: every half under a stable key; a skipped sweep says why. */
final readonly class MaintenanceTickJson
{
    public const string SKIPPED_REASON = 'refresh aborted: the shared EntityManager is unusable this tick';

    /** @param iterable<SweepReportJsonInterface> $mappers */
    public function __construct(
        #[AutowireIterator('app.sweep_report_json')]
        private iterable $mappers,
    ) {
    }

    /** @return array<string, array<string, int|bool|string>> */
    public function report(MaintenanceTickReportModel $report): array
    {
        return ['refresh' => RefreshReportJson::report($report->refresh)]
            + $this->sweeps($report->sweeps)
            + ['logShipping' => self::logShipping($report->logShipping)];
    }

    /** @return array<string, array<string, int|bool|string>> */
    private function sweeps(MaintenanceSweepsModel $sweeps): array
    {
        $json = [];
        foreach ($sweeps->reports as $name => $report) {
            $figures = $this->figures($report);
            $json[$name] = $sweeps->skipped ? $figures + ['skipped' => self::SKIPPED_REASON] : $figures;
        }

        return $json;
    }

    /** @return array<string, int|bool> */
    private function figures(object $report): array
    {
        foreach ($this->mappers as $mapper) {
            $figures = $mapper->figures($report);
            if (null !== $figures) {
                return $figures;
            }
        }

        throw new \LogicException(\sprintf('No tick JSON mapper claims %s.', $report::class));
    }

    /** @return array{shipped: int, failed: int} */
    private static function logShipping(LokiSpoolReportModel $report): array
    {
        return ['shipped' => $report->shipped, 'failed' => $report->failed];
    }
}
