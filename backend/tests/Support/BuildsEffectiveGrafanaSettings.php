<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Grafana\EffectiveGrafanaSettings;
use App\Service\Grafana\GrafanaEnvDefaults;
use App\Service\Grafana\GrafanaSettingsCache;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

trait BuildsEffectiveGrafanaSettings
{
    private function effectiveGrafanaSettingsOver(
        ?GrafanaSettingsEntity $row,
        GrafanaEnvDefaults $defaults = new GrafanaEnvDefaults('', '', ''),
        ?GrafanaSettingsCache $cache = null,
    ): EffectiveGrafanaSettings {
        $repository = $this->createStub(GrafanaSettingsRepository::class);
        $repository->method('findSingleton')->willReturn($row);

        return $this->effectiveGrafanaSettingsOverRepository($repository, $defaults, $cache);
    }

    private function effectiveGrafanaSettingsOverRepository(
        GrafanaSettingsRepository $repository,
        GrafanaEnvDefaults $defaults = new GrafanaEnvDefaults('', '', ''),
        ?GrafanaSettingsCache $cache = null,
    ): EffectiveGrafanaSettings {
        return new EffectiveGrafanaSettings(
            $repository,
            GrafanaApiKeyCiphers::withTestSecret(),
            $defaults,
            $cache ?? new GrafanaSettingsCache(new ArrayAdapter()),
        );
    }
}
