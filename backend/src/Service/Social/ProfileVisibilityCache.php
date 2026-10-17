<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Social\Model\HiddenProfileModel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ProfileVisibilityCache
{
    private const bool PUBLIC = false;

    public function __construct(
        #[Autowire(service: 'social.profile_visibility.cache')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function isPublic(string $actor): bool
    {
        return self::PUBLIC === $this->cache->getItem(self::keyOf($actor))->get();
    }

    public function hiddenOf(string $actor): ?HiddenProfileModel
    {
        $verdict = $this->cache->getItem(self::keyOf($actor))->get();

        return $verdict instanceof HiddenProfileModel ? $verdict : null;
    }

    /**
     * @param list<string>                      $asked
     * @param array<string, HiddenProfileModel> $hidden the hidden ones among $asked, by actor
     */
    public function remember(array $asked, array $hidden): void
    {
        foreach ($asked as $actor) {
            $item = $this->cache->getItem(self::keyOf($actor));
            $this->cache->saveDeferred($item->set($hidden[$actor] ?? self::PUBLIC));
        }
        $this->cache->commit();
    }

    /** A DID holds colons, which PSR-6 reserves in keys. */
    private static function keyOf(string $actor): string
    {
        return 'profile_visibility.' . hash('xxh128', $actor);
    }
}
