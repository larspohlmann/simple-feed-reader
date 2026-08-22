<?php

declare(strict_types=1);

namespace App\Service\Version;

/**
 * The whole version picture the API hands the client: which build is running,
 * the newest release upstream (or null when there is none to report), and
 * whether that release is worth updating to.
 */
final readonly class VersionReport
{
    public function __construct(
        public ReleaseVersion $running,
        public ?LatestRelease $latest,
        public bool $updateAvailable,
    ) {
    }
}
