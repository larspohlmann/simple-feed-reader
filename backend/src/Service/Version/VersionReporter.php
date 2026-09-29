<?php

declare(strict_types=1);

namespace App\Service\Version;

use App\Service\Version\LatestReleaseReader\LatestReleaseReaderInterface;
use App\Service\Version\Model\SemanticVersionModel;
use App\Service\Version\Model\VersionReportModel;
use App\Service\Version\ReleaseVersionReader\ReleaseVersionReaderInterface;

final readonly class VersionReporter
{
    public function __construct(
        private ReleaseVersionReaderInterface $releaseVersionReader,
        private LatestReleaseReaderInterface $latestReleaseReader,
    ) {
    }

    public function report(): VersionReportModel
    {
        $running = $this->releaseVersionReader->read();
        $latest = $this->latestReleaseReader->read();

        $updateAvailable = null !== $latest
            && SemanticVersionModel::isUpgrade($running->version, $latest->version);

        return new VersionReportModel($running, $latest, $updateAvailable);
    }
}
