<?php

declare(strict_types=1);

namespace App\Service\Trending\Pass;

/** One build's wall time and peak memory, both recorded with its snapshot (#2017). */
final readonly class BuildStopwatch
{
    private function __construct(private int $startedAtNanoseconds)
    {
    }

    /** Resets the process's peak-memory mark, so the peak read later is this build's own. */
    public static function start(): self
    {
        memory_reset_peak_usage();

        return new self(hrtime(true));
    }

    public function elapsedMilliseconds(): int
    {
        return intdiv(hrtime(true) - $this->startedAtNanoseconds, 1_000_000);
    }

    public function peakMemoryBytes(): int
    {
        return memory_get_peak_usage();
    }
}
