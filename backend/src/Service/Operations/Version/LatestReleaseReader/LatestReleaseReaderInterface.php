<?php

declare(strict_types=1);

namespace App\Service\Operations\Version\LatestReleaseReader;

use App\Service\Operations\Version\Model\LatestReleaseModel;

interface LatestReleaseReaderInterface
{
    /** Null when there is nothing to report (none cut, source unreachable, check off): silence, not an error. */
    public function read(): ?LatestReleaseModel;

    /** Asks upstream now; a failure keeps the release already known. */
    public function refresh(): ?LatestReleaseModel;
}
