<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Social\Model\SocialCountsModel;

final class BlueskyPostCounts
{
    private function __construct()
    {
    }

    /** The counts of an `app.bsky.feed.defs#postView`; null when one of likes, reposts or replies is missing. */
    public static function parsePost(JsonNodeModel $post): ?SocialCountsModel
    {
        return PostCounts::parseKeys($post, 'likeCount', 'repostCount', 'replyCount', $post->int('quoteCount'));
    }
}
