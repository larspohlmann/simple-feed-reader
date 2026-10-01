<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana;

use App\Entity\GrafanaConnection;
use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Service\Grafana\GrafanaSettingsCache;
use App\Service\Grafana\Model\GrafanaSettingsSnapshotModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class GrafanaSettingsCacheTest extends TestCase
{
    public function testTheLoaderRunsOnceAndLaterReadsAreServedFromThePool(): void
    {
        $cache = new GrafanaSettingsCache(new ArrayAdapter());
        $loads = 0;
        $loader = function () use (&$loads): GrafanaSettingsSnapshotModel {
            ++$loads;

            return $this->configuredSnapshot();
        };

        self::assertSame('tenant42', $cache->remember($loader)->connection->lokiUsername);
        self::assertSame('tenant42', $cache->remember($loader)->connection->lokiUsername);
        self::assertSame(1, $loads);
    }

    public function testForgetSendsTheNextReadBackToTheLoader(): void
    {
        $cache = new GrafanaSettingsCache(new ArrayAdapter());
        $loads = 0;
        $loader = function () use (&$loads): GrafanaSettingsSnapshotModel {
            ++$loads;

            return $this->configuredSnapshot();
        };

        $cache->remember($loader);
        $cache->forget();
        $cache->remember($loader);

        self::assertSame(2, $loads);
    }

    private function configuredSnapshot(): GrafanaSettingsSnapshotModel
    {
        $entity = new GrafanaSettingsEntity();
        $entity->applyWithoutToken(new GrafanaConnection(null, 'tenant42', null));

        return GrafanaSettingsSnapshotModel::fromEntity($entity);
    }
}
