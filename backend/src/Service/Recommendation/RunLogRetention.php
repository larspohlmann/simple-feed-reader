<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * How many runs keep their run log.
 *
 * It used to be one: the log was a live view of the run in front of you, so a
 * new run wiped the last (#309). Every prompt investigation since has been
 * comparative instead — #396 needed the run that returned a full list beside
 * the one that returned seven, #399 needed one run's per-batch reply sizes
 * beside another's — and a window one run wide leaves nothing to compare
 * against, so each question waits for the failure to happen a second time.
 * Since #638 the log is also the phase-timing history the ETA averages, so the
 * window is what bounds how many past runs that estimate learns from.
 *
 * Ten is bounded by what the rows cost, not by the questions: one run of 35
 * batches holds about 40 rows and 1.6 MB of prompts and replies, so this caps
 * an account at roughly 16 MB.
 *
 * It lives here rather than at either user because both must agree: the
 * starter trims to this window, and the debug panel offers exactly the runs
 * that survived it. A panel offering a run the starter has already deleted
 * would show an empty log and no reason for it.
 */
final readonly class RunLogRetention
{
    public const int RUNS = 10;

    private function __construct()
    {
    }
}
