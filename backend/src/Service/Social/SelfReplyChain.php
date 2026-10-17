<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Social\Pass\ReplyNode;

final readonly class SelfReplyChain
{
    public const int MAX_REPLIES = 3;

    /**
     * @param list<ReplyNode> $replies the post's direct replies
     *
     * @return list<EntryCommentModel>
     */
    public function of(array $replies): array
    {
        $chain = [];
        $level = $replies;
        while (\count($chain) < self::MAX_REPLIES) {
            $next = self::oldestByAuthor($level);
            if ($next === null) {
                break;
            }
            $chain[] = $next->comment();
            $level = $next->replies;
        }

        return $chain;
    }

    /** @param list<ReplyNode> $nodes */
    private static function oldestByAuthor(array $nodes): ?ReplyNode
    {
        $own = array_values(array_filter($nodes, static fn (ReplyNode $node): bool => $node->byEntryAuthor));
        usort(
            $own,
            static fn (ReplyNode $left, ReplyNode $right): int => self::sortKey($left) <=> self::sortKey($right),
        );

        return $own[0] ?? null;
    }

    /** @return array{bool, ?\DateTimeImmutable} an undated reply sorts after every dated one */
    private static function sortKey(ReplyNode $node): array
    {
        return [$node->publishedAt === null, $node->publishedAt];
    }
}
