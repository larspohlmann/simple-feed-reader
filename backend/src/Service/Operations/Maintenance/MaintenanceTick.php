<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance;

use App\Service\Ingestion\Refresh\Model\RefreshRequestModel;
use App\Service\Ingestion\Refresh\RefreshRunner\RefreshRunner;
use App\Service\Operations\Logging\Loki\LokiSpoolShipper;
use App\Service\Operations\Maintenance\MaintenanceSweep\MaintenanceSweepInterface;
use App\Service\Operations\Maintenance\Model\MaintenanceSweepsModel;
use App\Service\Operations\Maintenance\Model\MaintenanceTickReportModel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Refresh first, then the sweeps, then the log spool. An aborted refresh closed the shared
 * EntityManager, so the sweeps must not run that tick; the spool touches no EntityManager and ships anyway.
 * A sweep that closes the manager has it reset before the next one runs.
 */
final readonly class MaintenanceTick
{
    public const int REFRESH_BUDGET_SECONDS = 20;

    /** @param iterable<string, MaintenanceSweepInterface> $sweeps */
    public function __construct(
        private RefreshRunner $refreshRunner,
        #[AutowireIterator('app.maintenance_sweep', indexAttribute: 'index')]
        private iterable $sweeps,
        private LokiSpoolShipper $logSpoolShipper,
        private ClockInterface $clock,
        private ManagerRegistry $registry,
        private int $tickWindowSeconds,
    ) {
    }

    public function run(): MaintenanceTickReportModel
    {
        $deadline = $this->clock->now()->modify(\sprintf('+%d seconds', $this->tickWindowSeconds));
        $refresh = $this->refreshRunner->run(RefreshRequestModel::allDue(self::REFRESH_BUDGET_SECONDS));
        $sweeps = $refresh->isAborted() ? $this->skippedSweeps() : $this->sweep($deadline);

        return new MaintenanceTickReportModel($refresh, $sweeps, $this->logSpoolShipper->ship());
    }

    private function sweep(\DateTimeImmutable $deadline): MaintenanceSweepsModel
    {
        $reports = [];
        foreach ($this->sweeps as $name => $sweep) {
            $this->ensureManagerOpen();
            $reports[$name] = $sweep->run($deadline);
        }

        return MaintenanceSweepsModel::ran($reports);
    }

    private function ensureManagerOpen(): void
    {
        $manager = $this->registry->getManager();
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $this->registry->resetManager();
        }
    }

    private function skippedSweeps(): MaintenanceSweepsModel
    {
        $reports = [];
        foreach ($this->sweeps as $name => $sweep) {
            $reports[$name] = $sweep->idleReport();
        }

        return MaintenanceSweepsModel::skippedAfterAbortedRefresh($reports);
    }
}
