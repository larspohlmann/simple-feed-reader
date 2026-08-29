<?php

declare(strict_types=1);

namespace App\Service\Refresh;

/**
 * What one slice did, and where its run now stands.
 *
 * Two values because they answer two different questions and have two different
 * lifetimes: the report is this slice's, the progress is the run's. Hanging the
 * progress off RefreshReport instead would make it nullable for the CLI and
 * maintenance sweeps, which have no run to track and must not pay for one.
 */
final readonly class TrackedRefreshReport
{
    public function __construct(
        public RefreshReport $report,
        public RefreshRunProgress $progress,
    ) {
    }
}
