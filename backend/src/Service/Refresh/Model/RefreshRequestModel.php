<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model;

use App\Enum\FeedGroup;
use App\Repository\NetworkScope;
use App\Repository\RefreshTarget;

final readonly class RefreshRequestModel
{
    public const int DEFAULT_BATCH_LIMIT = 50;

    private function __construct(
        public ?int $userId,
        public RefreshTarget $target,
        public bool $force,
        public int $budgetSeconds,
        public bool $prune,
        public int $batchLimit = self::DEFAULT_BATCH_LIMIT,
    ) {
    }

    public static function allDue(int $budgetSeconds): self
    {
        return new self(null, RefreshTarget::everyFeed(), false, $budgetSeconds, true);
    }

    public static function forUser(int $userId, int $budgetSeconds): self
    {
        return new self($userId, RefreshTarget::everyFeed(), true, $budgetSeconds, false);
    }

    public static function forUserTag(int $userId, int $tagId, int $budgetSeconds): self
    {
        return new self($userId, RefreshTarget::tag($tagId), true, $budgetSeconds, false);
    }

    public static function forUserNetwork(int $userId, NetworkScope $networkScope, int $budgetSeconds): self
    {
        return new self($userId, RefreshTarget::network($networkScope), true, $budgetSeconds, false);
    }

    public static function forUserFeedGroup(int $userId, FeedGroup $feedGroup, int $budgetSeconds): self
    {
        return new self($userId, RefreshTarget::feedGroup($feedGroup), true, $budgetSeconds, false);
    }

    public static function forUserNeverFetched(int $userId, int $budgetSeconds): self
    {
        return new self($userId, RefreshTarget::neverFetchedFeeds(), true, $budgetSeconds, false);
    }

    public static function forFeed(int $feedId, int $budgetSeconds): self
    {
        return new self(null, RefreshTarget::feed($feedId), true, $budgetSeconds, false);
    }

    public static function forUserFeed(int $userId, int $feedId, int $budgetSeconds): self
    {
        return new self($userId, RefreshTarget::feed($feedId), true, $budgetSeconds, false);
    }

    public function withoutPruning(): self
    {
        return new self(
            $this->userId,
            $this->target,
            $this->force,
            $this->budgetSeconds,
            false,
            $this->batchLimit,
        );
    }

    public function ignoringSchedule(): self
    {
        return new self(
            $this->userId,
            $this->target,
            true,
            $this->budgetSeconds,
            $this->prune,
            $this->batchLimit,
        );
    }

    public function limitedTo(int $batchLimit): self
    {
        return new self(
            $this->userId,
            $this->target,
            $this->force,
            $this->budgetSeconds,
            $this->prune,
            $batchLimit,
        );
    }
}
