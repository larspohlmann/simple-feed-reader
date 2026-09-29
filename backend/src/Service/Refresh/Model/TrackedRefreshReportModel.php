<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model;

/**
 * What one slice did (the report) and where its run now stands (the progress). Kept apart so the CLI and
 * maintenance sweeps, which track no run, never carry a nullable progress.
 */
final readonly class TrackedRefreshReportModel
{
    public function __construct(
        public RefreshReportModel $report,
        public RefreshRunProgressModel $progress,
    ) {
    }
}
