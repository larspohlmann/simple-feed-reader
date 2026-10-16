<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\FollowAvatarsModel;
use App\Service\Social\Model\FollowedAccountModel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class FollowAvatarCache
{
    public function __construct(
        #[Autowire(service: 'social.follow_avatars.cache')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /** @param list<FollowedAccountModel> $follows */
    public function remember(SocialAccount $account, array $follows): void
    {
        if (!self::isTrusted($account)) {
            return;
        }
        $item = $this->cache->getItem(self::keyOf($account));
        $item->set(FollowAvatarsModel::of($follows)->toCacheEntry());
        $this->cache->save($item);
    }

    public function find(SocialAccount $account): FollowAvatarsModel
    {
        if (!self::isTrusted($account)) {
            return FollowAvatarsModel::none();
        }
        $item = $this->cache->getItem(self::keyOf($account));
        $avatars = $item->isHit() ? FollowAvatarsModel::fromCacheEntryOrNull($item->get()) : null;

        return $avatars ?? FollowAvatarsModel::none();
    }

    /** Bluesky's follows come from the public AppView; a Mastodon instance is the user's own pick and could name any picture. */
    private static function isTrusted(SocialAccount $account): bool
    {
        return SocialNetwork::Bluesky === $account->getNetwork();
    }

    private static function keyOf(SocialAccount $account): string
    {
        return 'follow_avatars.' . $account->requireId();
    }
}
