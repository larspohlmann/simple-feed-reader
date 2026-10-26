<?php

declare(strict_types=1);

namespace App\Service\Chapter;

use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Chapter\Model\EmbedDetailsModel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class EmbedDetailsCache
{
    private const int CHAPTERED_SECONDS = 2592000;
    private const int UNCHAPTERED_SECONDS = 86400;

    public function __construct(
        #[Autowire(service: 'embed.details.cache')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function find(EmbedAddressModel $embed): ?EmbedDetailsModel
    {
        $item = $this->cache->getItem(self::keyOf($embed));

        return $item->isHit() ? EmbedDetailsModel::fromCacheEntryOrNull($item->get()) : null;
    }

    public function remember(EmbedAddressModel $embed, EmbedDetailsModel $details): void
    {
        $item = $this->cache->getItem(self::keyOf($embed));
        $item->set($details->toCacheEntry());
        $item->expiresAfter($details->chapters === [] ? self::UNCHAPTERED_SECONDS : self::CHAPTERED_SECONDS);
        $this->cache->save($item);
    }

    private static function keyOf(EmbedAddressModel $embed): string
    {
        return 'embed_details.' . $embed->cacheKey();
    }
}
