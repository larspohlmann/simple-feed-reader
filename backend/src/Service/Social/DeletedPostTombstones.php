<?php

declare(strict_types=1);

namespace App\Service\Social;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Posts deleted from the reader, per feed, kept from being stored again while a lagging source still serves them. */
final readonly class DeletedPostTombstones
{
    private const int KEPT_SECONDS = 7 * 24 * 3600;

    public function __construct(
        #[Autowire(service: 'social.deleted_posts.cache')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    public function bury(int $feedId, string $guidHash): void
    {
        $item = $this->cache->getItem(self::keyOf($feedId));
        $item->set([...$this->standing($item), $guidHash => $this->now() + self::KEPT_SECONDS]);
        $this->cache->save($item);
    }

    /** @return list<string> the guid hashes of the feed's posts deleted within the last seven days */
    public function buriedIn(int $feedId): array
    {
        return array_keys($this->standing($this->cache->getItem(self::keyOf($feedId))));
    }

    /** @return array<string, int> each guid hash still buried, with the time it may be stored again */
    private function standing(CacheItemInterface $item): array
    {
        $buried = $item->get();
        if (!\is_array($buried)) {
            return [];
        }
        $now = $this->now();
        $standing = [];
        foreach ($buried as $guidHash => $until) {
            if (\is_string($guidHash) && \is_int($until) && $until > $now) {
                $standing[$guidHash] = $until;
            }
        }

        return $standing;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private static function keyOf(int $feedId): string
    {
        return 'deleted_posts.' . $feedId;
    }
}
