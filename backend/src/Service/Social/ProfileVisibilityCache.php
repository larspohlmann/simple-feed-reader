<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\ProfileVisibilityModel;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ProfileVisibilityCache
{
    public function __construct(
        #[Autowire(service: 'social.profile_visibility.cache')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param array<string> $actors
     *
     * @return array<string, ProfileVisibilityModel> by actor
     */
    public function visibilitiesOf(array $actors): array
    {
        $items = $this->itemsOf($actors);
        $visibilities = [];
        foreach ($actors as $actor) {
            $verdict = $items[self::keyOf($actor)]->get();
            $visibilities[$actor] = $verdict instanceof ProfileVisibilityModel
                ? $verdict
                : ProfileVisibilityModel::unknown();
        }

        return $visibilities;
    }

    /**
     * @param list<string>                      $asked
     * @param array<string, HiddenProfileModel> $hidden the hidden ones among $asked, by actor
     */
    public function remember(array $asked, array $hidden): void
    {
        $items = $this->itemsOf($asked);
        foreach ($asked as $actor) {
            $profile = $hidden[$actor] ?? null;
            $verdict = null === $profile
                ? ProfileVisibilityModel::publicProfile()
                : ProfileVisibilityModel::hiddenProfile($profile);
            $this->cache->saveDeferred($items[self::keyOf($actor)]->set($verdict));
        }
        $this->cache->commit();
    }

    /**
     * @param array<string> $actors
     *
     * @return array<string, CacheItemInterface> by key
     */
    private function itemsOf(array $actors): array
    {
        /** @var iterable<string, CacheItemInterface> $found PSR-6 keys each item by its key */
        $found = $this->cache->getItems(array_map(self::keyOf(...), $actors));
        $items = [];
        foreach ($found as $key => $item) {
            $items[$key] = $item;
        }

        return $items;
    }

    /** A DID holds colons, which PSR-6 reserves in keys. */
    private static function keyOf(string $actor): string
    {
        return hash('xxh128', $actor);
    }
}
