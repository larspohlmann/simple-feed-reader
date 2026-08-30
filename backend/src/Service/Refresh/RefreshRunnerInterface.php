<?php

declare(strict_types=1);

namespace App\Service\Refresh;

/**
 * One budgeted slice of refresh work.
 *
 * The seam exists so a caller that WRAPS a run — TrackedRefreshRunner, which folds
 * each slice into a run-wide tally — can be tested against prepared slices instead of
 * against the network, the clock and a database. RefreshRunner is final, so without
 * this there is no double to give it.
 */
interface RefreshRunnerInterface
{
    public function run(RefreshRequest $request): RefreshReport;
}
