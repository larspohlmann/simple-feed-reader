<?php

declare(strict_types=1);

namespace App\Service\Comments;

use App\Service\Comments\Model\CachedCommentsModel;
use App\Service\Comments\Model\CommentsAnswerModel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CommentsCache
{
    public function __construct(
        #[Autowire(service: 'comments.cache')]
        private CacheItemPoolInterface $cache,
        #[Autowire('%comments_cache_lifetime%')]
        private int $settledLifetime,
        #[Autowire('%comments_gathering_cache_lifetime%')]
        private int $gatheringLifetime,
    ) {
    }

    public function find(string $key): ?CachedCommentsModel
    {
        $item = $this->cache->getItem(self::keyOf($key));

        return $item->isHit() ? CachedCommentsModel::fromCacheEntryOrNull($item->get()) : null;
    }

    public function remember(string $key, CommentsAnswerModel $answer, \DateTimeImmutable $fetchedAt): void
    {
        $item = $this->cache->getItem(self::keyOf($key));
        $item->set((new CachedCommentsModel($answer->body, $fetchedAt))->toCacheEntry());
        $item->expiresAt($fetchedAt->modify('+' . $this->lifetimeOf($answer) . ' seconds'));
        $this->cache->save($item);
    }

    public function forget(string $key): void
    {
        $this->cache->deleteItem(self::keyOf($key));
    }

    private function lifetimeOf(CommentsAnswerModel $answer): int
    {
        return $answer->isStillGathering ? $this->gatheringLifetime : $this->settledLifetime;
    }

    private static function keyOf(string $key): string
    {
        return 'comments.' . hash('xxh128', $key);
    }
}
