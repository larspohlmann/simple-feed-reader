<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** A post's public counts as its network reports them now; Mastodon has no quotes. */
final readonly class SocialCountsModel
{
    public function __construct(
        public int $likes,
        public int $reposts,
        public int $replies,
        public ?int $quotes,
    ) {
    }

    /** @return array{likes: int, reposts: int, replies: int, quotes: int|null} */
    public function toCacheEntry(): array
    {
        return [
            'likes' => $this->likes,
            'reposts' => $this->reposts,
            'replies' => $this->replies,
            'quotes' => $this->quotes,
        ];
    }

    public static function fromCacheEntryOrNull(mixed $stored): ?self
    {
        if (!\is_array($stored)) {
            return null;
        }
        $likes = $stored['likes'] ?? null;
        $reposts = $stored['reposts'] ?? null;
        $replies = $stored['replies'] ?? null;
        $quotes = $stored['quotes'] ?? null;
        if (!\is_int($likes) || !\is_int($reposts) || !\is_int($replies) || (null !== $quotes && !\is_int($quotes))) {
            return null;
        }

        return new self($likes, $reposts, $replies, $quotes);
    }
}
