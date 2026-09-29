<?php

declare(strict_types=1);

namespace App\Service\Version\LatestReleaseReader;

use App\Service\Version\Model\LatestReleaseModel;

interface LatestReleaseReaderInterface
{
    /** Null when there is nothing to report (none cut, source unreachable, check off): silence, not an error. */
    public function read(): ?LatestReleaseModel;
}
