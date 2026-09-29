<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\VersionJson;
use App\Service\Version\Model\LatestReleaseModel;
use App\Service\Version\Model\ReleaseVersionModel;
use App\Service\Version\Model\VersionReportModel;
use PHPUnit\Framework\TestCase;

final class VersionJsonTest extends TestCase
{
    public function testMapsTheRunningBuildAndTheLatestRelease(): void
    {
        $report = new VersionReportModel(
            new ReleaseVersionModel('v1.2.0', 'abc1234', '2026-09-01T10:00:00Z'),
            new LatestReleaseModel('v1.3.0', 'https://example.com/releases/v1.3.0'),
            true,
        );

        self::assertSame([
            'version' => 'v1.2.0',
            'commit' => 'abc1234',
            'builtAt' => '2026-09-01T10:00:00Z',
            'latest' => ['version' => 'v1.3.0', 'notesUrl' => 'https://example.com/releases/v1.3.0'],
            'updateAvailable' => true,
        ], VersionJson::of($report));
    }

    public function testNoLatestReleaseMapsToNull(): void
    {
        $payload = VersionJson::of(new VersionReportModel(ReleaseVersionModel::development(), null, false));

        self::assertNull($payload['latest']);
        self::assertFalse($payload['updateAvailable']);
    }
}
