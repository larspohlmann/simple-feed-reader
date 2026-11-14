<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\MaintenanceSweep;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One sweep MaintenanceTick runs after the refresh, highest priority first; its name keys it in the tick's report.
 * An implementation narrows both return types to its module's own report model.
 */
#[AutoconfigureTag('app.maintenance_sweep')]
interface MaintenanceSweepInterface
{
    /** A sweep with no budget of its own runs whole, whatever the deadline. */
    public function run(\DateTimeImmutable $deadline): object;

    /** The report of a tick that could not run this sweep. */
    public function idleReport(): object;
}
