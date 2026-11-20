<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\SocialAccount;
use App\Service\Social\Social\Model\FollowAvatarsModel;
use App\Service\Social\Social\Model\FollowedAccountModel;
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
        $item = $this->cache->getItem(self::buildKey($account));
        $item->set(FollowAvatarsModel::fromFollows($follows)->toCacheEntry());
        $this->cache->save($item);
    }

    public function find(SocialAccount $account): FollowAvatarsModel
    {
        return FollowAvatarsModel::fromCacheEntry($this->cache->getItem(self::buildKey($account))->get());
    }

    private static function buildKey(SocialAccount $account): string
    {
        return 'follow_avatars.' . $account->requireId();
    }
}
