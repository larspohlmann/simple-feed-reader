<?php

declare(strict_types=1);

namespace App\Tests\Http\Admin;

use App\Entity\GrafanaSettings;
use App\Http\Admin\GrafanaSettingsJson;
use App\Service\Crypto\SealedSecret;
use App\Service\Grafana\GrafanaConnection;
use App\Service\Grafana\GrafanaEnvDefaults;
use PHPUnit\Framework\TestCase;

final class GrafanaSettingsJsonTest extends TestCase
{
    public function testNoRowFallsBackToDefaultsAndReportsContainerPresent(): void
    {
        $payload = GrafanaSettingsJson::from(null, $this->defaults(), false);

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
            new GrafanaConnection('https://cloud/loki/push', 'tenant42', 'https://cloud/grafana', null, false),
            new SealedSecret('c', 'n', 's', 1),
            'wxyz',
        );

        $payload = GrafanaSettingsJson::from($settings, $this->defaults(), false);

        self::assertSame('https://cloud/loki/push', $payload['lokiPushUrl']);
        self::assertSame('https://cloud/loki/push', $payload['lokiPushUrlEffective']);
        self::assertSame('tenant42', $payload['lokiUsername']);
        self::assertTrue($payload['hasToken']);
        self::assertSame('wxyz', $payload['tokenHint']);
        self::assertArrayNotHasKey('token', $payload);
    }

    public function testNoContainerWhenDefaultEmpty(): void
    {
        $payload = GrafanaSettingsJson::from(null, new GrafanaEnvDefaults('', '', ''), false);

        self::assertFalse($payload['containerPresent']);
        self::assertNull($payload['lokiPushUrlEffective']);
    }

    public function testProfilingOverrideToggleAndAvailabilityAreReported(): void
    {
        $settings = new GrafanaSettings();
        $settings->apply(
            new GrafanaConnection(null, null, null, 'http://custom:4040', true),
            new SealedSecret('c', 'n', 's', 1),
            'wxyz',
        );

        $payload = GrafanaSettingsJson::from($settings, $this->defaults(), true);

        self::assertSame('http://custom:4040', $payload['pyroscopePushUrl']);
        self::assertSame('http://pyroscope:4040', $payload['pyroscopePushUrlDefault']);
        self::assertSame('http://custom:4040', $payload['pyroscopePushUrlEffective']);
        self::assertTrue($payload['profilingContainerPresent']);
        self::assertTrue($payload['profilingEnabled']);
        self::assertTrue($payload['profilerAvailable']);
    }

    public function testProfilingReportsAbsentContainerAndOffToggleWithoutARow(): void
    {
        $payload = GrafanaSettingsJson::from(null, new GrafanaEnvDefaults('', '', ''), false);

        self::assertNull($payload['pyroscopePushUrlEffective']);
        self::assertFalse($payload['profilingContainerPresent']);
        self::assertFalse($payload['profilingEnabled']);
        self::assertFalse($payload['profilerAvailable']);
    }

    private function defaults(): GrafanaEnvDefaults
    {
        return new GrafanaEnvDefaults(
            'http://loki:3100/loki/api/v1/push',
            'http://localhost:3000',
            'http://pyroscope:4040',
        );
    }
}
