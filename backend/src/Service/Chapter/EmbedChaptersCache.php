<?php

declare(strict_types=1);

namespace App\Service\Chapter;

use App\Entity\EntryChapter;
use App\Entity\EntryMedia;
use App\Service\Chapter\Model\EmbedAddressModel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class EmbedChaptersCache
{
    private const int FOUND_SECONDS = 2592000;
    private const int NONE_SECONDS = 86400;

    public function __construct(
        #[Autowire(service: 'embed.chapters.cache')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /** @return list<EntryChapter>|null */
    public function find(EmbedAddressModel $embed): ?array
    {
        $item = $this->cache->getItem(self::keyOf($embed));

        return $item->isHit() ? self::chaptersFrom($item->get()) : null;
    }

    /** @param list<EntryChapter> $chapters */
    public function remember(EmbedAddressModel $embed, array $chapters): void
    {
        $item = $this->cache->getItem(self::keyOf($embed));
        $item->set(EntryMedia::toJsonList($chapters));
        $item->expiresAfter($chapters === [] ? self::NONE_SECONDS : self::FOUND_SECONDS);
        $this->cache->save($item);
    }

    /** @return list<EntryChapter>|null */
    private static function chaptersFrom(mixed $stored): ?array
    {
        if (!\is_array($stored) || !array_is_list($stored)) {
            return null;
        }
        $chapters = [];
        foreach ($stored as $entry) {
            if (!\is_array($entry) || array_is_list($entry)) {
                return null;
            }
            /** @var array<string, mixed> $entry */
            if (!EntryChapter::isComplete($entry)) {
                return null;
            }
            $chapters[] = EntryChapter::fromStored($entry);
        }

        return $chapters;
    }

    private static function keyOf(EmbedAddressModel $embed): string
    {
        return 'embed_chapters.' . $embed->cacheKey();
    }
}
