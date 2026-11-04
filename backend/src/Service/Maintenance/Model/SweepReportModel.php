<?php

declare(strict_types=1);

namespace App\Service\Maintenance\Model;

/** What one maintenance sweep did in a tick, figure by figure, in the order the tick reports them. */
final readonly class SweepReportModel
{
    /** @param array<string, int|bool> $outcome */
    public function __construct(public array $outcome)
    {
    }
}
