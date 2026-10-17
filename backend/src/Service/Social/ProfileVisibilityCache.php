<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\ProfileVisibilityModel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ProfileVisibilityCache
{
    public function __construct(
        #[Autowire(service: 'social.profile_visibility.cache')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function visibilityOf(string $actor): ProfileVisibilityModel
    {
        $verdict = $this->cache->getItem(self::keyOf($actor))->get();

        return $verdict instanceof ProfileVisibilityModel ? $verdict : ProfileVisibilityModel::unknown();
    }

    /**
     * @param list<string>                      $asked
     * @param array<string, HiddenProfileModel> $hidden the hidden ones among $asked, by actor
     */
    public function remember(array $asked, array $hidden): void
    {
        foreach ($asked as $actor) {
            $profile = $hidden[$actor] ?? null;
            $verdict = null === $profile
                ? ProfileVisibilityModel::publicProfile()
                : ProfileVisibilityModel::hiddenProfile($profile);
            $this->cache->saveDeferred($this->cache->getItem(self::keyOf($actor))->set($verdict));
        }
        $this->cache->commit();
    }

    /** A DID holds colons, which PSR-6 reserves in keys. */
    private static function keyOf(string $actor): string
    {
        return hash('xxh128', $actor);
    }
}
