<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Social\Crypto\BlueskySessionCipher;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\Model\SealedBlueskySessionModel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class BlueskySessionCache
{
    /** Well under the access token's own lifetime, so a cached session is rarely found stale. */
    private const int LIFETIME_SECONDS = 5400;

    public function __construct(
        #[Autowire(service: 'bluesky.session.cache')]
        private CacheItemPoolInterface $cache,
        private BlueskySessionCipher $cipher,
    ) {
    }

    public function find(SocialAccount $account): ?BlueskySessionModel
    {
        $item = $this->cache->getItem(self::keyOf($account));
        $sealed = $item->isHit() ? SealedBlueskySessionModel::fromCacheEntryOrNull($item->get()) : null;
        if (null === $sealed) {
            return null;
        }

        try {
            $accessJwt = $this->cipher->open($account->getUser()->requireId(), $sealed->accessJwt);
        } catch (SecretUnreadableException) {
            return null;
        }

        return new BlueskySessionModel($accessJwt, $sealed->handle);
    }

    public function remember(SocialAccount $account, BlueskySessionModel $session): void
    {
        $sealed = new SealedBlueskySessionModel(
            $this->cipher->seal($account->getUser()->requireId(), $session->accessJwt),
            $session->handle,
        );
        $item = $this->cache->getItem(self::keyOf($account));
        $item->set($sealed->toCacheEntry());
        $item->expiresAfter(self::LIFETIME_SECONDS);
        $this->cache->save($item);
    }

    public function forget(SocialAccount $account): void
    {
        $this->cache->deleteItem(self::keyOf($account));
    }

    /** Keyed by the stored credential too: a reconnect seals a new one, and the old password's session must go. */
    private static function keyOf(SocialAccount $account): string
    {
        return sprintf(
            'bluesky_session.%d.%s',
            $account->requireId(),
            hash('xxh128', $account->getSealedCredential()->ciphertext),
        );
    }
}
