<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Service\Grafana\GrafanaConnection;
use App\Service\Grafana\GrafanaSettingsCache;
use App\Service\Grafana\GrafanaSettingsSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class GrafanaSettingsCacheTest extends TestCase
{
    public function testTheLoaderRunsOnceAndLaterReadsAreServedFromThePool(): void
    {
        $cache = new GrafanaSettingsCache(new ArrayAdapter());
        $loads = 0;
        $loader = function () use (&$loads): GrafanaSettingsSnapshot {
            ++$loads;

            return $this->enabledSnapshot();
        };

        self::assertTrue($cache->remember($loader)->toEntity()->isProfilingEnabled());
        self::assertTrue($cache->remember($loader)->toEntity()->isProfilingEnabled());
        self::assertSame(1, $loads);
    }

    public function testForgetSendsTheNextReadBackToTheLoader(): void
    {
        $cache = new GrafanaSettingsCache(new ArrayAdapter());
        $loads = 0;
        $loader = function () use (&$loads): GrafanaSettingsSnapshot {
            ++$loads;

            return $this->enabledSnapshot();
        };

        $cache->remember($loader);
        $cache->forget();
        $cache->remember($loader);

        self::assertSame(2, $loads);
    }

    private function enabledSnapshot(): GrafanaSettingsSnapshot
    {
        $entity = new GrafanaSettingsEntity();
        $entity->applyWithoutToken(new GrafanaConnection(null, null, null, null, true));

        return GrafanaSettingsSnapshot::fromEntity($entity);
    }
}
