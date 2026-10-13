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
        $likes = $post->int('likeCount');
        $reposts = $post->int('repostCount');
        $replies = $post->int('replyCount');
        if (null === $likes || null === $reposts || null === $replies) {
            return null;
        }

        return new SocialCountsModel($likes, $reposts, $replies, $post->int('quoteCount'));
    }

    /** The counts of a Mastodon `Status`; null when one of them is missing. */
    public static function ofMastodonStatus(JsonNodeModel $status): ?SocialCountsModel
    {
        $likes = $status->int('favourites_count');
        $reposts = $status->int('reblogs_count');
        $replies = $status->int('replies_count');
        if (null === $likes || null === $reposts || null === $replies) {
            return null;
        }

        return new SocialCountsModel($likes, $reposts, $replies, null);
    }
}
