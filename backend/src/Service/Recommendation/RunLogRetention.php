<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * How many runs keep their run log.
 *
 * It used to be one: the log was a live view of the current run, so a new run wiped the
 * last (#309). Investigations since have been comparative instead -- #396 and #399 each
 * needed one run set beside another, and a one-run-wide window leaves nothing to compare
 * against. Since #638 the log is also the phase-timing history the ETA averages, so the
 * window bounds how many past runs that estimate learns from.
 *
 * Ten is bounded by what the rows cost, not by the questions: one run of 35 batches holds
 * about 40 rows and 1.6 MB of prompts and replies, capping an account at roughly 16 MB.
 *
 * It lives here rather than at either user because both must agree: the starter trims to
 * this window, and the debug panel offers exactly the runs that survived it -- a panel
 * offering a run the starter already deleted would show an empty log with no reason for it.
 */
final readonly class RunLogRetention
{
    public const int RUNS = 10;

    private function __construct()
    {
    }
}
