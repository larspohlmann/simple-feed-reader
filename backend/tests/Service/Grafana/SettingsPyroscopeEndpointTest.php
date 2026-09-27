<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana;

use App\Service\Grafana\GrafanaEnvDefaults;
use App\Service\Grafana\SettingsPyroscopeEndpoint;
use App\Tests\Support\BuildsEffectiveGrafanaSettings;
use PHPUnit\Framework\TestCase;

final class SettingsPyroscopeEndpointTest extends TestCase
{
    use BuildsEffectiveGrafanaSettings;

    public function testReadsTheEffectivePushUrl(): void
    {
        self::assertSame('http://pyroscope:4040', $this->endpoint('http://pyroscope:4040')->pushUrl());
    }

    public function testReturnsNullWhenNoPushUrlIsConfigured(): void
    {
        self::assertNull($this->endpoint('')->pushUrl());
    }

    private function endpoint(string $pyroscopeDefault): SettingsPyroscopeEndpoint
    {
        $defaults = new GrafanaEnvDefaults('', '', $pyroscopeDefault);

        return new SettingsPyroscopeEndpoint($this->effectiveGrafanaSettingsOver(null, $defaults));
    }
}
