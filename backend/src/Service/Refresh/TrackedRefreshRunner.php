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
