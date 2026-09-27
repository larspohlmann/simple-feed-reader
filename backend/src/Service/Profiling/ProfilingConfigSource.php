<?php

declare(strict_types=1);

namespace App\Service\Profiling;

interface ProfilingConfigSource
{
    public function profilingEnabled(): bool;

    /** Drops what this process has read, so the next read sees a save made by another process. */
    public function refresh(): void;
}
