<?php

declare(strict_types=1);

namespace App\Service\Refresh;

final readonly class RefreshRequest
{
    private function __construct(
        public ?int $userId,
        public ?int $feedId,
        public bool $force,
        public int $budgetSeconds,
        public bool $prune,
    ) {
    }

    public static function allDue(int $budgetSeconds, bool $prune = true, bool $force = false): self
    {
        return new self(null, null, $force, $budgetSeconds, $prune);
    }

    public static function forUser(int $userId, int $budgetSeconds): self
    {
        return new self($userId, null, true, $budgetSeconds, false);
    }

    public static function forFeed(int $feedId, int $budgetSeconds): self
    {
        return new self(null, $feedId, true, $budgetSeconds, false);
    }
}
