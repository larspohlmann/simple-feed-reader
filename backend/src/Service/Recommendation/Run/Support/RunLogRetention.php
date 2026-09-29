<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Support;

/**
 * How many runs keep their run log: the debug panel compares runs, and the ETA averages their phase timings. Ten
 * caps an account near 16 MB (a 35-batch run holds about 40 rows, 1.6 MB). The starter trims to it and the debug panel
 * offers exactly the survivors, so both read this one constant.
 */
final readonly class RunLogRetention
{
    public const int RUNS = 10;

    private function __construct()
    {
    }
}
