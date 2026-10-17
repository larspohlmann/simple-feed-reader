<?php

declare(strict_types=1);

namespace App\Service\Social;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PublicProfileCache
{
    public function __construct(
        #[Autowire(service: 'social.public_profiles.cache')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function isPublic(string $actor): bool
    {
        return $this->cache->getItem(self::keyOf($actor))->isHit();
    }

    /** @param list<string> $actors */
    public function rememberPublic(array $actors): void
    {
        foreach ($actors as $actor) {
            $this->cache->saveDeferred($this->cache->getItem(self::keyOf($actor))->set(true));
        }
        $this->cache->commit();
    }

    /** A DID holds colons, which PSR-6 reserves in keys. */
    private static function keyOf(string $actor): string
    {
        return 'public_profile.' . hash('xxh128', $actor);
    }
}
