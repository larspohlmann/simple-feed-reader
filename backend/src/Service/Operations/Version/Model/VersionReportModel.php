<?php

declare(strict_types=1);

namespace App\Service\Version\Model;

/**
 * The whole version picture the API hands the client: which build is running,
 * the newest release upstream (or null when there is none to report), and
 * whether that release is worth updating to.
 */
final readonly class VersionReportModel
{
    public function __construct(
        public ReleaseVersionModel $running,
        public ?LatestReleaseModel $latest,
        public bool $updateAvailable,
    ) {
    }
}
