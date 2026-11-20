<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Entity\SocialAccount;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Social\Bluesky\Model\BlueskyPostKeyModel;
use App\Service\Social\Bluesky\Support\BlueskyTid;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/** The record key each idempotency key posts under, so a resent draft lands on the record its first send made. */
final readonly class BlueskyPostKeys
{
    public function __construct(
        #[Target('social.bluesky_post_keys.cache')]
        private CacheItemPoolInterface $recordKeys,
        private NaiveUtcClock $clock,
    ) {
    }

    public function ensureRecordKey(SocialAccount $account, string $idempotencyKey): BlueskyPostKeyModel
    {
        $item = $this->recordKeys->getItem(sprintf('%d.%s', $account->requireId(), hash('xxh128', $idempotencyKey)));
        $remembered = $item->get();
        if (\is_string($remembered)) {
            return new BlueskyPostKeyModel($remembered, seenBefore: true);
        }

        $recordKey = BlueskyTid::at($this->clock->now(), crc32($idempotencyKey));
        $this->recordKeys->save($item->set($recordKey));

        return new BlueskyPostKeyModel($recordKey, seenBefore: false);
    }
}
