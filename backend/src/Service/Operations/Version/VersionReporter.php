<?php

declare(strict_types=1);

namespace App\Service\Operations\Version;

use App\Service\Operations\Version\LatestReleaseReader\LatestReleaseReaderInterface;
use App\Service\Operations\Version\Model\LatestReleaseModel;
use App\Service\Operations\Version\Model\SemanticVersionModel;
use App\Service\Operations\Version\Model\VersionReportModel;
use App\Service\Operations\Version\ReleaseVersionReader\ReleaseVersionReaderInterface;

final readonly class VersionReporter
{
    public function __construct(
        private ReleaseVersionReaderInterface $releaseVersionReader,
        private LatestReleaseReaderInterface $latestReleaseReader,
    ) {
    }

    public function report(): VersionReportModel
    {
        return $this->reportAgainst($this->latestReleaseReader->read());
    }

    public function freshReport(): VersionReportModel
    {
        return $this->reportAgainst($this->latestReleaseReader->refresh());
    }

    private function reportAgainst(?LatestReleaseModel $latest): VersionReportModel
    {
        $running = $this->releaseVersionReader->read();
        $updateAvailable = null !== $latest
            && SemanticVersionModel::isUpgrade($running->version, $latest->version);

        return new VersionReportModel($running, $latest, $updateAvailable);
    }
}
