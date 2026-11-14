<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\Support\SocialPostAddress;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class SocialCountsCache
{
    public function __construct(
        #[Autowire(service: 'social.counts.cache')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function find(SocialPostAddressModel $post): ?SocialCountsModel
    {
        $item = $this->cache->getItem(self::keyOf($post));

        return $item->isHit() ? SocialCountsModel::fromCacheEntryOrNull($item->get()) : null;
    }

    public function remember(SocialPostAddressModel $post, SocialCountsModel $counts): void
    {
        $item = $this->cache->getItem(self::keyOf($post));
        $item->set($counts->toCacheEntry());
        $this->cache->save($item);
    }

    public function forget(SocialPostAddressModel $post): void
    {
        $this->cache->deleteItem(self::keyOf($post));
    }

    /** An entry that is no network post has no counts to forget. */
    public function forgetEntry(Entry $entry): void
    {
        $post = SocialPostAddress::of($entry);
        if (null !== $post) {
            $this->forget($post);
        }
    }

    private static function keyOf(SocialPostAddressModel $post): string
    {
        return 'social_counts.' . hash('xxh128', $post->network->value . ' ' . $post->address);
    }
}
