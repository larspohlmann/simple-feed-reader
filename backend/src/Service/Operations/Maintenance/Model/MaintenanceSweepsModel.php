<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\Model;

/** The tick's sweeps that flush through the default EntityManager, which an aborted refresh leaves closed. */
final readonly class MaintenanceSweepsModel
{
    /** @param array<string, object> $reports by sweep name, in the order they ran */
    private function __construct(
        public array $reports,
        public bool $skipped,
    ) {
    }

    /** @param array<string, object> $reports */
    public static function ran(array $reports): self
    {
        return new self($reports, false);
    }

    /** @param array<string, object> $idleReports */
    public static function skippedAfterAbortedRefresh(array $idleReports): self
    {
        return new self($idleReports, true);
    }
}
