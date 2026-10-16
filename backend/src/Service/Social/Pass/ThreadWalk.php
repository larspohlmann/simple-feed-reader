<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Social\Model\ReplyNodeModel;

final class ThreadWalk
{
    /** @var list<EntryCommentModel> */
    private array $comments = [];

    private int $omitted = 0;

    public function __construct(private readonly int $maxDepth, private readonly int $maxCount)
    {
    }

    /** @param list<ReplyNodeModel> $replies */
    public function walkNewestFirst(array $replies): void
    {
        usort($replies, self::newestFirst(...));
        $this->walkAll($replies, 0);
    }

    /** @return list<EntryCommentModel> */
    public function comments(): array
    {
        return $this->comments;
    }

    public function omitted(): int
    {
        return $this->omitted;
    }

    /** @param list<ReplyNodeModel> $nodes */
    private function walkAll(array $nodes, int $depth): void
    {
        foreach ($nodes as $node) {
            $this->walk($node, $depth);
        }
    }

    private function walk(ReplyNodeModel $node, int $depth): void
    {
        if ($depth >= $this->maxDepth || count($this->comments) >= $this->maxCount) {
            $this->omitted += self::sizeOf($node);

            return;
        }
        $this->comments[] = $node->comment->atDepth($depth);
        $replies = $node->replies;
        usort($replies, self::oldestFirst(...));
        $this->walkAll($replies, $depth + 1);
    }

    private static function newestFirst(ReplyNodeModel $left, ReplyNodeModel $right): int
    {
        return self::timeOf($right) <=> self::timeOf($left);
    }

    private static function oldestFirst(ReplyNodeModel $left, ReplyNodeModel $right): int
    {
        return self::timeOf($left) <=> self::timeOf($right);
    }

    private static function sizeOf(ReplyNodeModel $node): int
    {
        return 1 + array_sum(array_map(self::sizeOf(...), $node->replies));
    }

    private static function timeOf(ReplyNodeModel $node): int
    {
        return $node->comment->publishedAt?->getTimestamp() ?? PHP_INT_MIN;
    }
}
