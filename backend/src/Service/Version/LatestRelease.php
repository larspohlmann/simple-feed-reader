<?php

declare(strict_types=1);

namespace App\Service\Version;

/**
 * The newest published release upstream: the tag it carries and the page that
 * holds its notes. Fetched from GitHub, never written by the app.
 */
final readonly class LatestRelease
{
    public function __construct(
        public string $version,
        public string $notesUrl,
    ) {
    }
}
