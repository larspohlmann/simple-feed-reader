<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Service\Content\Comments\Model\EntryCommentModel;
use App\Service\Social\Social\Model\SelfReplyChainModel;
use App\Service\Social\Social\Pass\ReplyNode;
use App\Service\Social\Social\Support\OldestFirstUndatedLast;

final readonly class SelfReplyChain
{
    public const int MAX_REPLIES = 4;

    /** @param list<ReplyNode> $replies the post's direct replies */
    public function buildChain(array $replies): SelfReplyChainModel
    {
        $chain = [];
        $next = self::oldestByAuthor($replies);
        while ($next !== null) {
            $chain[] = $next;
            $next = self::oldestByAuthor($next->replies);
        }

        return new SelfReplyChainModel(
            array_map(
                static fn (ReplyNode $node): EntryCommentModel => $node->comment(),
                \array_slice($chain, 0, self::MAX_REPLIES),
            ),
            \count($chain),
        );
    }

    /** @param list<ReplyNode> $nodes */
    private static function oldestByAuthor(array $nodes): ?ReplyNode
    {
        $own = array_filter($nodes, static fn (ReplyNode $node): bool => $node->byEntryAuthor);
        usort(
            $own,
            static fn (ReplyNode $left, ReplyNode $right): int
                => OldestFirstUndatedLast::compare($left->publishedAt, $right->publishedAt),
        );

        return $own[0] ?? null;
    }
}
