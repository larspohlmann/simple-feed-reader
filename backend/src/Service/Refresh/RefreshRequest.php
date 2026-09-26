<?php

declare(strict_types=1);

namespace App\Service\Refresh;

final readonly class RefreshRequest
{
    private function __construct(
        public ?int $userId,
        public ?int $feedId,
        public ?int $tagId,
        public bool $force,
        public int $budgetSeconds,
        public bool $prune,
    ) {
    }

    public static function allDue(int $budgetSeconds): self
    {
        return new self(null, null, null, false, $budgetSeconds, true);
    }

    public static function forUser(int $userId, int $budgetSeconds): self
    {
        return new self($userId, null, null, true, $budgetSeconds, false);
    }

    public static function forUserTag(int $userId, int $tagId, int $budgetSeconds): self
    {
        return new self($userId, null, $tagId, true, $budgetSeconds, false);
    }

    public static function forFeed(int $feedId, int $budgetSeconds): self
    {
        return new self(null, $feedId, null, true, $budgetSeconds, false);
    }

    public static function forUserFeed(int $userId, int $feedId, int $budgetSeconds): self
    {
        return new self($userId, $feedId, null, true, $budgetSeconds, false);
    }

    public function withoutPruning(): self
    {
        return new self($this->userId, $this->feedId, $this->tagId, $this->force, $this->budgetSeconds, false);
    }

    public function ignoringSchedule(): self
    {
        return new self($this->userId, $this->feedId, $this->tagId, true, $this->budgetSeconds, $this->prune);
    }
}
