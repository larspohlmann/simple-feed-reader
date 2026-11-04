<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One maintenance sweep's report shaped for /maintenance/tick; null means the report belongs to another mapper. */
#[AutoconfigureTag('app.sweep_report_json')]
interface SweepReportJsonInterface
{
    /** @return array<string, int|bool>|null */
    public function figures(object $report): ?array;
}
