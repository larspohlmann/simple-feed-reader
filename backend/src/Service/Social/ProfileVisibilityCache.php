<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\ProfileVisibilityModel;
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

    public function visibilityOf(string $actor): ProfileVisibilityModel
    {
        $verdict = $this->cache->getItem(self::keyOf($actor))->get();

        return match (true) {
            $verdict instanceof HiddenProfileModel => ProfileVisibilityModel::hiddenProfile($verdict),
            self::PUBLIC === $verdict => ProfileVisibilityModel::publicProfile(),
            default => ProfileVisibilityModel::unknown(),
        };
    }

    /**
     * @param list<string>                      $asked
     * @param array<string, HiddenProfileModel> $hidden the hidden ones among $asked, by actor
     */
    public function remember(array $asked, array $hidden): void
    {
        $actorByKey = [];
        foreach ($asked as $actor) {
            $actorByKey[self::keyOf($actor)] = $actor;
        }
        foreach ($this->cache->getItems(array_keys($actorByKey)) as $key => $item) {
            $this->cache->saveDeferred($item->set($hidden[$actorByKey[$key]] ?? self::PUBLIC));
        }
        $this->cache->commit();
    }

    /** A DID holds colons, which PSR-6 reserves in keys. */
    private static function keyOf(string $actor): string
    {
        return hash('xxh128', $actor);
    }
}
