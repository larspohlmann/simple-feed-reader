<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana;

use App\Service\Grafana\GrafanaSettings;
use App\Service\Grafana\SettingsPyroscopeEndpoint;
use PHPUnit\Framework\TestCase;

final class SettingsPyroscopeEndpointTest extends TestCase
{
    public function testDelegatesToTheSettingsService(): void
    {
        $settings = $this->createStub(GrafanaSettings::class);
        $settings->method('effectivePyroscopePushUrl')->willReturn('http://pyroscope:4040');
        $endpoint = new SettingsPyroscopeEndpoint($settings);

        self::assertSame('http://pyroscope:4040', $endpoint->pushUrl());
    }

    public function testReturnsNullWhenTheServiceHasNoEndpoint(): void
    {
        $settings = $this->createStub(GrafanaSettings::class);
        $settings->method('effectivePyroscopePushUrl')->willReturn(null);
        $endpoint = new SettingsPyroscopeEndpoint($settings);

        self::assertNull($endpoint->pushUrl());
    }
}
