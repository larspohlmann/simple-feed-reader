<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * Holds the Grafana singleton row across requests so the profiling enablement
 * check on every request (#1012), and the Loki and Pyroscope endpoint reads,
 * stop querying the database on the hot path.
 *
 * A shared pool, not the per-request memo GrafanaSettings already keeps: the
 * php-fpm process that saves the admin form and the long-running worker that
 * re-checks the toggle are different processes, so the invalidation must cross
 * the process boundary. forget() runs on every admin save; the lifetime is only
 * a backstop for an entry whose invalidation was somehow lost.
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
