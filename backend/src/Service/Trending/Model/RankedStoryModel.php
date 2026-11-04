<?php

declare(strict_types=1);

namespace App\Service\Trending\Model;

final readonly class RankedStoryModel
{
    /** @param int $outletCount distinct feeds of this reader in the story, social accounts included */
    public function __construct(public int $storyId, public int $outletCount, public \DateTimeImmutable $lastSeen)
    {
    }

    /** Widest first, then newest: the order Trending lists its stories in. */
    public static function compare(self $left, self $right): int
    {
        return [$right->outletCount, $right->lastSeen, $right->storyId]
            <=> [$left->outletCount, $left->lastSeen, $left->storyId];
    }

    /**
     * @param list<self> $stories
     *
     * @return list<int>
     */
    public static function storyIdsOf(array $stories): array
    {
        return array_map(static fn (self $story): int => $story->storyId, $stories);
    }

    public function withMoreOutlets(int $added): self
    {
        return new self($this->storyId, $this->outletCount + $added, $this->lastSeen);
    }
}
