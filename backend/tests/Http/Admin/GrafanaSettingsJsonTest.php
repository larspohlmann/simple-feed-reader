<?php

declare(strict_types=1);

namespace App\Tests\Http\Admin;

use App\Entity\GrafanaSettings;
use App\Http\Admin\GrafanaSettingsJson;
use App\Service\Crypto\SealedSecret;
use App\Service\Grafana\GrafanaConnection;
use PHPUnit\Framework\TestCase;

final class GrafanaSettingsJsonTest extends TestCase
{
    public function testNoRowFallsBackToDefaultsAndReportsContainerPresent(): void
    {
        $payload = GrafanaSettingsJson::from(null, 'http://loki:3100/loki/api/v1/push', 'http://localhost:3000');

        self::assertNull($payload['lokiPushUrl']);
        self::assertSame('http://loki:3100/loki/api/v1/push', $payload['lokiPushUrlDefault']);
        self::assertSame('http://loki:3100/loki/api/v1/push', $payload['lokiPushUrlEffective']);
        self::assertNull($payload['grafanaUrl']);
        self::assertSame('http://localhost:3000', $payload['grafanaUrlEffective']);
        self::assertFalse($payload['hasToken']);
        self::assertSame('', $payload['tokenHint']);
        self::assertNull($payload['lokiUsername']);
        self::assertTrue($payload['containerPresent']);
    }

    public function testOverrideWinsOverDefaultAndSecretNeverLeaks(): void
    {
        $settings = new GrafanaSettings();
        $settings->apply(
            new GrafanaConnection('https://cloud/loki/push', 'tenant42', 'https://cloud/grafana'),
            new SealedSecret('c', 'n', 's', 1),
            'wxyz',
        );

        $payload = GrafanaSettingsJson::from(
            $settings,
            'http://loki:3100/loki/api/v1/push',
            'http://localhost:3000',
        );

        self::assertSame('https://cloud/loki/push', $payload['lokiPushUrl']);
        self::assertSame('https://cloud/loki/push', $payload['lokiPushUrlEffective']);
        self::assertSame('tenant42', $payload['lokiUsername']);
        self::assertTrue($payload['hasToken']);
        self::assertSame('wxyz', $payload['tokenHint']);
        self::assertArrayNotHasKey('token', $payload);
    }

    public function testNoContainerWhenDefaultEmpty(): void
    {
        $payload = GrafanaSettingsJson::from(null, '', '');

        self::assertFalse($payload['containerPresent']);
        self::assertNull($payload['lokiPushUrlEffective']);
    }
}
