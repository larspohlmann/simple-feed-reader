<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Model\SocialCountsModel;

final class PostCounts
{
    private function __construct()
    {
    }

    /** The counts of an `app.bsky.feed.defs#postView`; null when one of likes, reposts or replies is missing. */
    public static function ofBlueskyPost(JsonNodeModel $post): ?SocialCountsModel
    {
        return self::ofKeys($post, 'likeCount', 'repostCount', 'replyCount', $post->int('quoteCount'));
    }

    /** The counts of a Mastodon `Status`; null when one of them is missing. Quotes count from 4.5 on. */
    public static function ofMastodonStatus(JsonNodeModel $status): ?SocialCountsModel
    {
        return self::ofKeys(
            $status,
            'favourites_count',
            'reblogs_count',
            'replies_count',
            $status->int('quotes_count'),
        );
    }

    private static function ofKeys(
        JsonNodeModel $node,
        string $likesKey,
        string $repostsKey,
        string $repliesKey,
        ?int $quotes,
    ): ?SocialCountsModel {
        $likes = $node->int($likesKey);
        $reposts = $node->int($repostsKey);
        $replies = $node->int($repliesKey);
        if (null === $likes || null === $reposts || null === $replies) {
            return null;
        }

        return new SocialCountsModel($likes, $reposts, $replies, $quotes);
    }
}
