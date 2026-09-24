<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class HostThrottle
{
    public function __construct(
        #[Autowire(service: 'host_throttle.cache')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    public function record(string $url, int $seconds): void
    {
        $item = $this->cache->getItem(self::key($url));
        $item->set($this->clock->now()->getTimestamp() + $seconds);
        $item->expiresAfter($seconds);
        $this->cache->save($item);
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
