<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * Shares the Grafana row between processes: php-fpm saves the admin form while the long-running worker re-checks the
 * profiling toggle, so the invalidation must cross the process boundary (#1012). The lifetime is only a backstop.
 */
final readonly class GrafanaSettingsCache
{
    private const string KEY = 'grafana_settings_singleton';
    private const int LIFETIME_SECONDS = 300;

    public function __construct(private CacheItemPoolInterface $grafanaSettingsCache)
    {
    }

    /**
     * @param callable():GrafanaSettingsSnapshot $loadFromDatabase
     *
     * @throws InvalidArgumentException
     */
    public function remember(callable $loadFromDatabase): GrafanaSettingsSnapshot
    {
        $item = $this->grafanaSettingsCache->getItem(self::KEY);
        $cached = $item->isHit() ? GrafanaSettingsSnapshot::fromArrayOrNull($item->get()) : null;
        if (null !== $cached) {
            return $cached;
        }

        $snapshot = $loadFromDatabase();
        $item->set($snapshot->toArray());
        $item->expiresAfter(self::LIFETIME_SECONDS);
        $this->grafanaSettingsCache->save($item);

        return $snapshot;
    }

    /** @throws InvalidArgumentException */
    public function forget(): void
    {
        $this->grafanaSettingsCache->deleteItem(self::KEY);
    }
}
