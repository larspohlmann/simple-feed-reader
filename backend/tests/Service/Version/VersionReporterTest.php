<?php

declare(strict_types=1);

namespace App\Tests\Service\Version;

use App\Service\Version\LatestRelease;
use App\Service\Version\LatestReleaseReader;
use App\Service\Version\ReleaseVersion;
use App\Service\Version\ReleaseVersionReader;
use App\Service\Version\VersionReporter;
use PHPUnit\Framework\TestCase;

final class VersionReporterTest extends TestCase
{
    public function testReportsTheRunningBuild(): void
    {
        $report = $this->reporter(
            new ReleaseVersion('v1.4.1', 'abc123', '2026-01-01T00:00:00Z'),
            null,
        )->report();

        self::assertSame('v1.4.1', $report->running->version);
        self::assertSame('abc123', $report->running->commit);
        self::assertNull($report->latest);
        self::assertFalse($report->updateAvailable);
    }

    public function testSignalsAnUpdateWhenTheLatestReleaseIsNewer(): void
    {
        $report = $this->reporter(
            new ReleaseVersion('v1.4.1', 'abc123', ''),
            new LatestRelease('v1.4.2', 'https://example.test/notes'),
        )->report();

        self::assertTrue($report->updateAvailable);
        self::assertNotNull($report->latest);
        self::assertSame('v1.4.2', $report->latest->version);
        self::assertSame('https://example.test/notes', $report->latest->notesUrl);
    }

    public function testStaysQuietWhenADevTagIsAheadOfTheLatestRelease(): void
    {
        $report = $this->reporter(
            new ReleaseVersion('v1.4.2-dev.3', 'abc123', ''),
            new LatestRelease('v1.4.1', 'https://example.test/notes'),
        )->report();

        self::assertFalse($report->updateAvailable);
        // The latest release is still reported; only the decision is "no update".
        self::assertNotNull($report->latest);
    }

    public function testStaysQuietForADevelopmentBuild(): void
    {
        $report = $this->reporter(
            ReleaseVersion::development(),
            new LatestRelease('v1.4.2', 'https://example.test/notes'),
        )->report();

        self::assertFalse($report->updateAvailable);
    }

    private function reporter(ReleaseVersion $running, ?LatestRelease $latest): VersionReporter
    {
        $releaseReader = new class ($running) implements ReleaseVersionReader {
            public function __construct(private readonly ReleaseVersion $version)
            {
            }

            public function read(): ReleaseVersion
            {
                return $this->version;
            }
        };

        $latestReader = new class ($latest) implements LatestReleaseReader {
            public function __construct(private readonly ?LatestRelease $latest)
            {
            }

            public function read(): ?LatestRelease
            {
                return $this->latest;
            }
        };

        return new VersionReporter($releaseReader, $latestReader);
    }
}
