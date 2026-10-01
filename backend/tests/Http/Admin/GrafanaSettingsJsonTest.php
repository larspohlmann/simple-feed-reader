<?php

declare(strict_types=1);

namespace App\Tests\Http\Admin;

use App\Entity\GrafanaConnection;
use App\Entity\GrafanaSettings;
use App\Entity\SealedSecret;
use App\Http\Admin\GrafanaSettingsJson;
use App\Service\Grafana\GrafanaEnvDefaults;
use App\Service\Grafana\Model\GrafanaSettingsOverviewModel;
use App\Service\Grafana\Model\GrafanaSettingsSnapshotModel;
use PHPUnit\Framework\TestCase;

final class GrafanaSettingsJsonTest extends TestCase
{
    public function testNoRowFallsBackToDefaultsAndReportsContainerPresent(): void
    {
        $payload = GrafanaSettingsJson::from(
            new GrafanaSettingsOverviewModel($this->unconfigured(), $this->defaults()),
        );

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
        $stored = new GrafanaSettingsSnapshotModel(
            new GrafanaConnection('https://cloud/loki/push', 'tenant42', 'https://cloud/grafana'),
            new SealedSecret('c', 'n', 's', 1),
            'wxyz',
        );

        $payload = GrafanaSettingsJson::from(new GrafanaSettingsOverviewModel($stored, $this->defaults()));

        self::assertSame('https://cloud/loki/push', $payload['lokiPushUrl']);
        self::assertSame('https://cloud/loki/push', $payload['lokiPushUrlEffective']);
        self::assertSame('tenant42', $payload['lokiUsername']);
        self::assertSame('https://cloud/grafana', $payload['grafanaUrl']);
        self::assertSame('https://cloud/grafana', $payload['grafanaUrlEffective']);
        self::assertTrue($payload['hasToken']);
        self::assertSame('wxyz', $payload['tokenHint']);
        self::assertArrayNotHasKey('token', $payload);
        self::assertArrayNotHasKey('sealedToken', $payload);
    }

    public function testNoContainerWhenDefaultEmpty(): void
    {
        $payload = GrafanaSettingsJson::from(
            new GrafanaSettingsOverviewModel($this->unconfigured(), new GrafanaEnvDefaults('', '')),
        );

        self::assertFalse($payload['containerPresent']);
        self::assertNull($payload['lokiPushUrlEffective']);
    }

    private function unconfigured(): GrafanaSettingsSnapshotModel
    {
        return GrafanaSettingsSnapshotModel::fromEntity(new GrafanaSettings());
    }

    private function defaults(): GrafanaEnvDefaults
    {
        return new GrafanaEnvDefaults('http://loki:3100/loki/api/v1/push', 'http://localhost:3000');
    }
}
