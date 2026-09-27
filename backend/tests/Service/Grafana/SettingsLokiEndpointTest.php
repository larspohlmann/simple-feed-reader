<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana;

use App\Entity\GrafanaConnection;
use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Service\Grafana\GrafanaEnvDefaults;
use App\Service\Grafana\SettingsLokiEndpoint;
use App\Tests\Support\BuildsEffectiveGrafanaSettings;
use App\Tests\Support\GrafanaApiKeyCiphers;
use PHPUnit\Framework\TestCase;

final class SettingsLokiEndpointTest extends TestCase
{
    use BuildsEffectiveGrafanaSettings;

    public function testReadsTheEffectiveLokiConnection(): void
    {
        $row = new GrafanaSettingsEntity();
        $row->apply(
            new GrafanaConnection(null, 'tenant42', null, null, false),
            GrafanaApiKeyCiphers::withTestSecret()->seal('secret'),
            'cret',
        );
        $defaults = new GrafanaEnvDefaults('http://loki:3100/loki/api/v1/push', '', '');

        $endpoint = new SettingsLokiEndpoint($this->effectiveGrafanaSettingsOver($row, $defaults));

        self::assertSame('http://loki:3100/loki/api/v1/push', $endpoint->pushUrl());
        self::assertSame('tenant42', $endpoint->username());
        self::assertSame('secret', $endpoint->token());
    }
}
