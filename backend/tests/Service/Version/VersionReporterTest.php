<?php

declare(strict_types=1);

namespace App\Tests\Service\Version;

use App\Service\Version\LatestReleaseReader\LatestReleaseReaderInterface;
use App\Service\Version\Model\LatestReleaseModel;
use App\Service\Version\Model\ReleaseVersionModel;
use App\Service\Version\ReleaseVersionReader\ReleaseVersionReaderInterface;
use App\Service\Version\VersionReporter;
use App\Tests\Support\VersionReporters;
use PHPUnit\Framework\TestCase;

final class VersionReporterTest extends TestCase
{
    public function testReportsTheRunningBuild(): void
    {
        $report = VersionReporters::reporting(
            new ReleaseVersionModel('v1.4.1', 'abc123', '2026-01-01T00:00:00Z'),
            null,
        )->report();

        self::assertSame('v1.4.1', $report->running->version);
        self::assertSame('abc123', $report->running->commit);
        self::assertNull($report->latest);
        self::assertFalse($report->updateAvailable);
    }

    public function testSignalsAnUpdateWhenTheLatestReleaseIsNewer(): void
    {
        $report = VersionReporters::reporting(
            new ReleaseVersionModel('v1.4.1', 'abc123', ''),
            new LatestReleaseModel('v1.4.2', 'https://example.test/notes'),
        )->report();

        self::assertTrue($report->updateAvailable);
        self::assertNotNull($report->latest);
        self::assertSame('v1.4.2', $report->latest->version);
        self::assertSame('https://example.test/notes', $report->latest->notesUrl);
    }

    public function testStaysQuietWhenADevTagIsAheadOfTheLatestRelease(): void
    {
        $report = VersionReporters::reporting(
            new ReleaseVersionModel('v1.4.2-dev.3', 'abc123', ''),
            new LatestReleaseModel('v1.4.1', 'https://example.test/notes'),
        )->report();

        self::assertFalse($report->updateAvailable);
        // The latest release is still reported; only the decision is "no update".
        self::assertNotNull($report->latest);
    }

    public function testStaysQuietForADevelopmentBuild(): void
    {
        $report = VersionReporters::reporting(
            ReleaseVersionModel::development(),
            new LatestReleaseModel('v1.4.2', 'https://example.test/notes'),
        )->report();

        self::assertFalse($report->updateAvailable);
    }

    public function testAFreshReportTakesTheRefreshedRelease(): void
    {
        $running = new class implements ReleaseVersionReaderInterface {
            public function read(): ReleaseVersionModel
            {
                return new ReleaseVersionModel('v1.4.1', 'abc123', '');
            }
        };
        $latest = new class implements LatestReleaseReaderInterface {
            public function read(): ?LatestReleaseModel
            {
                return null;
            }

            public function refresh(): LatestReleaseModel
            {
                return new LatestReleaseModel('v1.4.2', 'https://example.test/notes');
            }
        };
        $reporter = new VersionReporter($running, $latest);

        self::assertFalse($reporter->report()->updateAvailable);
        self::assertTrue($reporter->freshReport()->updateAvailable);
    }
}
