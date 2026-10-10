<?php

declare(strict_types=1);

namespace App\Service\LinkCard;

use App\Repository\TriedPostsInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The posts whose link cards were built once, so opening one never fetches its pages again. */
final readonly class TriedPosts implements TriedPostsInterface
{
    public function __construct(
        #[Autowire(service: 'post_link_cards.cache')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function mark(int $entryId): void
    {
        $this->cache->save($this->cache->getItem(self::key($entryId))->set(true));
    }

    public function wasTried(int $entryId): bool
    {
        return $this->cache->hasItem(self::key($entryId));
    }

    public function triedAmong(array $entryIds): array
    {
        $keys = array_map(self::key(...), $entryIds);
        $idsByKey = array_combine($keys, $entryIds);
        $tried = [];
        foreach ($this->cache->getItems($keys) as $item) {
            if ($item instanceof CacheItemInterface && $item->isHit()) {
                $tried[] = $idsByKey[$item->getKey()];
            }
        }

        return $tried;
    }

    private static function key(int $entryId): string
    {
        return (string) $entryId;
    }
}
