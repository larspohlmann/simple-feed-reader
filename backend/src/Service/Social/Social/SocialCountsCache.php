<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\Entry;
use App\Service\Social\Social\Model\SocialCountsModel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** A post's counts for a short while, kept per canonical item so every copy shows what one copy fetched. */
final readonly class SocialCountsCache
{
    public function __construct(
        #[Autowire(service: 'social.counts.cache')]
        private CacheItemPoolInterface $cache,
        private SocialPostAddresses $postAddresses,
    ) {
    }

    public function find(Entry $entry): ?SocialCountsModel
    {
        $key = $this->findKey($entry);
        if (null === $key) {
            return null;
        }
        $item = $this->cache->getItem($key);

        return $item->isHit() ? SocialCountsModel::fromCacheEntryOrNull($item->get()) : null;
    }

    public function remember(Entry $entry, SocialCountsModel $counts): void
    {
        $key = $this->findKey($entry);
        if (null === $key) {
            return;
        }
        $item = $this->cache->getItem($key);
        $item->set($counts->toCacheEntry());
        $this->cache->save($item);
    }

    public function forget(Entry $entry): void
    {
        $key = $this->findKey($entry);
        if (null !== $key) {
            $this->cache->deleteItem($key);
        }
    }

    private function findKey(Entry $entry): ?string
    {
        $itemId = $entry->getCanonicalItem()?->getId();
        if (null !== $itemId) {
            return 'social_counts.item.' . $itemId;
        }
        $post = $this->postAddresses->findPost($entry);

        return null === $post ? null : 'social_counts.' . hash('xxh128', $post->network->value . ' ' . $post->address);
    }
}
