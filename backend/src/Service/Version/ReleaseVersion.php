<?php

declare(strict_types=1);

namespace App\Service\Version;

/**
 * Which build is running: the tag it was cut from, the commit it was built at,
 * and when. Written by deploy/strato/build-release.sh, never by the app.
 */
final readonly class ReleaseVersion
{
    public function __construct(
        public string $version,
        public string $commit,
        public string $builtAt,
    ) {
    }

    /**
     * What a checkout without a deployed version.json reports — every local run
     * and every Docker run. `builtAt` is empty because there is no build to
     * date: a made-up timestamp would be worse than none.
     */
    public static function development(): self
    {
        return new self('dev', 'local', '');
    }
}
