<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana;

use App\Service\Grafana\GrafanaSettings;
use App\Service\Grafana\SettingsLokiEndpoint;
use PHPUnit\Framework\TestCase;

final class SettingsLokiEndpointTest extends TestCase
{
    public function testDelegatesToTheSettingsService(): void
    {
        $settings = $this->createStub(GrafanaSettings::class);
        $settings->method('effectiveLokiPushUrl')->willReturn('http://loki:3100/loki/api/v1/push');
        $settings->method('lokiUsername')->willReturn('tenant42');
        $settings->method('lokiToken')->willReturn('secret');
        $endpoint = new SettingsLokiEndpoint($settings);

        self::assertSame('http://loki:3100/loki/api/v1/push', $endpoint->pushUrl());
        self::assertSame('tenant42', $endpoint->username());
        self::assertSame('secret', $endpoint->token());
    }
}
