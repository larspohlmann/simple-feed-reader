<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Version\LatestReleaseReader\LatestReleaseReaderInterface;
use App\Service\Version\Model\LatestReleaseModel;
use App\Service\Version\Model\ReleaseVersionModel;
use App\Service\Version\ReleaseVersionReader\ReleaseVersionReaderInterface;
use App\Service\Version\VersionReporter;

final readonly class VersionReporters
{
    public static function reporting(ReleaseVersionModel $running, ?LatestReleaseModel $latest): VersionReporter
    {
        $releaseReader = new class ($running) implements ReleaseVersionReaderInterface {
            public function __construct(private readonly ReleaseVersionModel $version)
            {
            }

            public function read(): ReleaseVersionModel
            {
                return $this->version;
            }
        };

        $latestReader = new class ($latest) implements LatestReleaseReaderInterface {
            public function __construct(private readonly ?LatestReleaseModel $latest)
            {
            }

            public function read(): ?LatestReleaseModel
            {
                return $this->latest;
            }

            public function refresh(): ?LatestReleaseModel
            {
                return $this->latest;
            }
        };

        return new VersionReporter($releaseReader, $latestReader);
    }
}
