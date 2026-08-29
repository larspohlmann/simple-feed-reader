<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use Psr\Cache\InvalidArgumentException;

/**
 * Runs one slice and folds it into the run it belongs to.
 *
 * The ONLY place run-wide accounting happens. RefreshRunner is deliberately left
 * alone: it already carries thirteen collaborators, and the CLI and maintenance
 * sweeps — which nothing polls — must not pay for a feature that exists for the
 * polling client.
 *
 * Two quirks on the abort path, neither reachable by anything a user sees:
 *
 * - When {@see RefreshReport::aborted()} reports `remaining = 0` (the batch had
 *   started every feed before persistence failed), `advancedBy()` takes
 *   {@see RefreshRunProgress}'s completion branch and the run reads as full even
 *   though it stopped early. This is invisible: the run is over either way, so
 *   the bar unmounts, and the failure alert replaces the counted banner on the
 *   same strip.
 * - `aborted()`'s `remaining` is a lower bound derived from the current batch, at
 *   most `RefreshRunner::BATCH_LIMIT`, not a run-wide count. A 200-feed sweep
 *   that aborts on its very first slice therefore folds in `3 / 50`, not
 *   `3 / 200`. Every later slice is protected by `advancedBy()`'s `max()`,
 *   because the denominator is already established by then — only a first-slice
 *   abort can understate it.
 */
final readonly class TrackedRefreshRunner
{
    public function __construct(
        private RefreshRunnerInterface $refreshRunner,
        private RefreshRunStore $runs,
    ) {
    }

    /** @throws InvalidArgumentException */
    public function run(RefreshRequest $request): TrackedRefreshReport
    {
        $progress = $this->runs->open($request);
        $report = $this->refreshRunner->run($request);

        // The lock was held, so no slice ran. Its counters are all zero including
        // `remaining`, and folding those in would drop the denominator to whatever
        // was already done and report the run as finished.
        if (RefreshReport::STATUS_BUSY === $report->status) {
            return new TrackedRefreshReport($report, $progress);
        }

        $advanced = $progress->advancedBy($this->handledIn($report), $report->remaining);

        if (0 === $report->remaining || $report->isAborted()) {
            $this->runs->forget($request);

            return new TrackedRefreshReport($report, $advanced);
        }

        $this->runs->save($request, $advanced);

        return new TrackedRefreshReport($report, $advanced);
    }

    /**
     * Every outcome that ends a feed's turn, not only a successful fetch. A 304, a
     * failure and a 429 all take their feed out of `remaining`, so leaving them out
     * here would strand the bar short of full. Feeds the time budget deferred are
     * absent on purpose: they never started, and `remaining` still counts them.
     */
    private function handledIn(RefreshReport $report): int
    {
        return $report->fetched + $report->notModified + $report->failed + $report->throttled;
    }
}
