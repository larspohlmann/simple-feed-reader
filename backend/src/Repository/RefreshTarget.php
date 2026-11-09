<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\FeedGroup;

final readonly class RefreshTarget
{
    private function __construct(
        public ?int $feedId = null,
        public ?int $tagId = null,
        public ?NetworkScope $networkScope = null,
        public ?FeedGroup $feedGroup = null,
        public bool $neverFetched = false,
    ) {
    }

    public static function everyFeed(): self
    {
        return new self();
    }

    public static function feed(int $feedId): self
    {
        return new self(feedId: $feedId);
    }

    public static function tag(int $tagId): self
    {
        return new self(tagId: $tagId);
    }

    public static function network(NetworkScope $networkScope): self
    {
        return new self(networkScope: $networkScope);
    }

    public static function feedGroup(FeedGroup $feedGroup): self
    {
        return new self(feedGroup: $feedGroup);
    }

    public static function neverFetchedFeeds(): self
    {
        return new self(neverFetched: true);
    }

    public function runKey(): string
    {
        return match (true) {
            null !== $this->feedId => 'feed-' . $this->feedId,
            null !== $this->tagId => 'tag-' . $this->tagId,
            null !== $this->networkScope => 'set-network-' . $this->networkScope->toRunKey(),
            null !== $this->feedGroup => 'group-' . $this->feedGroup->value,
            $this->neverFetched => 'never-fetched',
            default => 'all',
        };
    }
}
