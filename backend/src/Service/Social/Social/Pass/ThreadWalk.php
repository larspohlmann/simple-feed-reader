<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Pass;

use App\Service\Content\Comments\Model\EntryCommentModel;

final class ThreadWalk
{
    /** @var list<EntryCommentModel> */
    private array $comments = [];

    private int $omitted = 0;

    public function __construct(private readonly int $maxDepth, private readonly int $maxCount)
    {
    }

    /** @param list<ReplyNode> $replies */
    public function walkInOrder(array $replies): void
    {
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

    /** @param list<ReplyNode> $nodes */
    private function walkAll(array $nodes, int $depth): void
    {
        foreach ($nodes as $node) {
            $this->walk($node, $depth);
        }
    }

    private function walk(ReplyNode $node, int $depth): void
    {
        if ($depth >= $this->maxDepth || count($this->comments) >= $this->maxCount) {
            $this->omitted += self::sizeOf($node);

            return;
        }
        $this->comments[] = $node->comment()->atDepth($depth);
        $replies = $node->replies;
        usort($replies, self::oldestFirst(...));
        $this->walkAll($replies, $depth + 1);
    }

    private static function oldestFirst(ReplyNode $left, ReplyNode $right): int
    {
        return $left->sortableTime() <=> $right->sortableTime();
    }

    private static function sizeOf(ReplyNode $node): int
    {
        return 1 + array_sum(array_map(self::sizeOf(...), $node->replies));
    }
}
