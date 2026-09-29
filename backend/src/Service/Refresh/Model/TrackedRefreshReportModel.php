<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model;

/**
 * What one slice did, and where its run now stands.
 *
 * Two values because they answer two different questions and have two different
 * lifetimes: the report is this slice's, the progress is the run's. Hanging the
 * progress off RefreshReportModel instead would make it nullable for the CLI and
 * maintenance sweeps, which have no run to track and must not pay for one.
 */
final readonly class TrackedRefreshReportModel
{
    public function __construct(
        public RefreshReportModel $report,
        public RefreshRunProgressModel $progress,
    ) {
    }
}
