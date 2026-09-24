<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class HostThrottle
{
    public const int MINIMUM_WAIT_SECONDS = 60;
    public const int MAXIMUM_WAIT_SECONDS = 86400;

    public function __construct(
        #[Autowire(service: 'host_throttle.cache')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    /** Returns the wait now in force: a shorter request never cuts a longer recorded one short. */
    public function record(string $url, ?int $seconds): int
    {
        $wait = max($this->remainingSeconds($url), self::clamped($seconds ?? self::MINIMUM_WAIT_SECONDS));
        $now = $this->clock->now();
        $until = $now->setTimestamp($now->getTimestamp() + $wait);

        $item = $this->cache->getItem(self::key($url));
        $item->set($until->getTimestamp());
        $item->expiresAt($until);
        $this->cache->save($item);

        return $wait;
    }

    public function remainingSeconds(string $url): int
    {
        $until = $this->cache->getItem(self::key($url))->get();

        return \is_int($until) ? max(0, $until - $this->clock->now()->getTimestamp()) : 0;
    }

    private static function clamped(int $seconds): int
    {
        return max(self::MINIMUM_WAIT_SECONDS, min(self::MAXIMUM_WAIT_SECONDS, $seconds));
    }

    private static function key(string $url): string
    {
        return 'host_throttle.' . hash('xxh128', HostKey::forUrl($url));
    }
}
