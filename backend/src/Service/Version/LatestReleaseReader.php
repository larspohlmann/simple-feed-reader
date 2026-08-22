<?php

declare(strict_types=1);

namespace App\Service\Version;

interface LatestReleaseReader
{
    /**
     * The newest published release upstream, or null when there is none to
     * report — no release cut yet, the source unreachable, or the check turned
     * off. A null is not an error the caller must handle: it means "say
     * nothing", which is exactly the resting state of the update badge.
     */
    public function read(): ?LatestRelease;
}
