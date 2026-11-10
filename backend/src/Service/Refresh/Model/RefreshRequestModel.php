<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model;

use App\Service\Refresh\Model\RefreshTarget\EveryFeedTargetModel;
use App\Service\Refresh\Model\RefreshTarget\FeedTargetModel;
use App\Service\Refresh\Model\RefreshTarget\RefreshTargetModelInterface;

final readonly class RefreshRequestModel
{
    public const int DEFAULT_BATCH_LIMIT = 50;

    private function __construct(
        public ?int $userId,
        public RefreshTargetModelInterface $target,
        public bool $force,
        public int $budgetSeconds,
        public bool $prune,
        public int $batchLimit = self::DEFAULT_BATCH_LIMIT,
    ) {
    }

    public static function allDue(int $budgetSeconds): self
    {
        return new self(null, new EveryFeedTargetModel(), false, $budgetSeconds, true);
    }

    public static function forUser(int $userId, RefreshTargetModelInterface $target, int $budgetSeconds): self
    {
        return new self($userId, $target, true, $budgetSeconds, false);
    }

    public static function forFeed(int $feedId, int $budgetSeconds): self
    {
        return new self(null, new FeedTargetModel($feedId), true, $budgetSeconds, false);
    }

    public function withoutPruning(): self
    {
        return clone($this, ['prune' => false]);
    }

    public function ignoringSchedule(): self
    {
        return clone($this, ['force' => true]);
    }

    public function limitedTo(int $batchLimit): self
    {
        return clone($this, ['batchLimit' => $batchLimit]);
    }
}
