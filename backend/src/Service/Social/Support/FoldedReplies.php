<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Social\Pass\ReplyNode;

/** Drops the replies already folded into the entry, lifting their own replies into their place. */
final class FoldedReplies
{
    private function __construct()
    {
    }

    /**
     * @param list<ReplyNode> $nodes
     * @param list<string>    $urls
     *
     * @return list<ReplyNode>
     */
    public static function without(array $nodes, array $urls): array
    {
        $survivors = array_map(
            static fn (ReplyNode $node): array => self::survivorsOf($node, $urls),
            $nodes,
        );

        return array_merge([], ...$survivors);
    }

    /**
     * @param list<string> $urls
     *
     * @return list<ReplyNode>
     */
    private static function survivorsOf(ReplyNode $node, array $urls): array
    {
        $replies = self::without($node->replies, $urls);
        if (in_array($node->url, $urls, true)) {
            return $replies;
        }
        if ($replies === $node->replies) {
            return [$node];
        }

        return [$node->withReplies($replies)];
    }
}
