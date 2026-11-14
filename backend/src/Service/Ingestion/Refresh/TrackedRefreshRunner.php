<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Service\Refresh\Model\RefreshReportModel;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Refresh\Model\RefreshRunProgressModel;
use App\Service\Refresh\Model\TrackedRefreshReportModel;
use App\Service\Refresh\RefreshRunner\RefreshRunnerInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * Runs one slice and folds it into its run: the only run-wide accounting, so the unpolled CLI and maintenance sweeps
 * never pay for it. An aborted slice's progress is approximate (its `remaining` is bounded by the batch), which no
 * user sees: the failure alert replaces the counted banner.
 */
final readonly class TrackedRefreshRunner
{
    public function __construct(
        private RefreshRunnerInterface $refreshRunner,
        private RefreshRunStore $runs,
    ) {
    }

    /** @throws InvalidArgumentException */
    public function run(RefreshRequestModel $request): TrackedRefreshReportModel
    {
        $progress = $this->runs->open($request);
        $report = $this->refreshRunner->run($request);

        // The lock was held, so no slice ran. Its counters are all zero including
        // `remaining`, and folding those in would drop the denominator to whatever
        // was already done and report the run as finished.
        if (RefreshReportModel::STATUS_BUSY === $report->status) {
            return new TrackedRefreshReportModel($report, $progress);
        }

        $advanced = $progress->advancedBy($this->handledIn($report), $report->remaining);

        if (0 === $report->remaining || $report->isAborted()) {
            $this->runs->forget($request);

            return new TrackedRefreshReportModel($report, $advanced);
        }

        $this->runs->save($request, $advanced);

        return new TrackedRefreshReportModel($report, $advanced);
    }

    /**
     * Every outcome that ends a feed's turn: a 304, a failure and a 429 leave `remaining` too. Feeds the budget
     * deferred are absent on purpose, since `remaining` still counts them.
     */
    private function handledIn(RefreshReportModel $report): int
    {
        return $report->fetched + $report->notModified + $report->failed + $report->throttled;
    }
}
