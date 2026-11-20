<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky\Support;

use App\Service\Content\Comments\Model\CommentPostModel;
use App\Service\Content\Comments\Model\CommentViewerModel;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\BlueskyPost\Support\AtPostUri;

/** The post a reply in a Bluesky thread answer is; null when the answer names no address or no counts for it. */
final class BlueskyCommentPost
{
    private function __construct()
    {
    }

    public static function parsePost(JsonNodeModel $post): ?CommentPostModel
    {
        $uri = $post->string('uri');
        $counts = BlueskyPostCounts::parsePost($post);
        if (null === $uri || !AtPostUri::matches($uri) || null === $counts) {
            return null;
        }
        $viewer = $post->node('viewer');
        $commentViewer = new CommentViewerModel(
            null !== BlueskyRecordUri::keepIfLike($viewer->string('like')),
            null !== BlueskyRecordUri::keepIfRepost($viewer->string('repost')),
            canRepost: true,
            canReply: !$viewer->isTrue('replyDisabled'),
            canQuote: !$viewer->isTrue('embeddingDisabled'),
        );

        return new CommentPostModel(
            $uri,
            $counts->toCommentCounts(),
            $commentViewer,
            $post->node('author')->string('did'),
        );
    }
}
