<?php

declare(strict_types=1);

namespace App\Service\Maintenance\MaintenanceSweep;

use App\Service\Maintenance\Model\SweepReportModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One sweep MaintenanceTick runs after the refresh, highest priority first; its name keys it in the tick's report. */
#[AutoconfigureTag('app.maintenance_sweep')]
interface MaintenanceSweepInterface
{
    public static function name(): string;

    public static function priority(): int;

    /** A sweep with no budget of its own runs whole, whatever the deadline. */
    public function run(\DateTimeImmutable $deadline): SweepReportModel;

    /** The all-zero report of a tick that could not run this sweep. */
    public function skippedReport(): SweepReportModel;
}
