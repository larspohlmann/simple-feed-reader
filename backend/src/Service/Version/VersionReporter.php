<?php

declare(strict_types=1);

namespace App\Service\Version;

/**
 * Joins the running build with the newest release upstream and decides whether
 * an update is worth showing. The decision is the strict semver ordering
 * (see SemanticVersion): a release only counts as an update when it ranks
 * above the running version, so a dev-tagged instance that is already ahead
 * stays quiet.
 */
final readonly class VersionReporter
{
    public function __construct(
        private ReleaseVersionReader $releaseVersionReader,
        private LatestReleaseReader $latestReleaseReader,
    ) {
    }

    public function report(): VersionReport
    {
        $running = $this->releaseVersionReader->read();
        $latest = $this->latestReleaseReader->read();

        $updateAvailable = null !== $latest
            && SemanticVersion::isUpgrade($running->version, $latest->version);

        return new VersionReport($running, $latest, $updateAvailable);
    }
}
