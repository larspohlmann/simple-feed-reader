<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class HostThrottle
{
    /** The bounds every recorded wait is clamped to, shared by every caller. */
    public const int MINIMUM_WAIT_SECONDS = 60;
    public const int MAXIMUM_WAIT_SECONDS = 86400;

    public function __construct(
        #[Autowire(service: 'host_throttle.cache')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Clamps the requested wait to [{@see self::MINIMUM_WAIT_SECONDS},
     * {@see self::MAXIMUM_WAIT_SECONDS}] and returns the wait actually
     * recorded, so a site naming 0 still rations and one naming years does
     * not strand the host indefinitely.
     */
    public function record(string $url, int $seconds): int
    {
        $wait = max(self::MINIMUM_WAIT_SECONDS, min(self::MAXIMUM_WAIT_SECONDS, $seconds));

        $item = $this->cache->getItem(self::key($url));
        $item->set($this->clock->now()->getTimestamp() + $wait);
        $item->expiresAfter($wait);
        $this->cache->save($item);

        return $wait;
    }

    public function remainingSeconds(string $url): int
    {
        $until = $this->cache->getItem(self::key($url))->get();

        return \is_int($until) ? max(0, $until - $this->clock->now()->getTimestamp()) : 0;
    }

    private static function key(string $url): string
    {
        return 'host_throttle.' . hash('xxh128', HostKey::forUrl($url));
    }
}
