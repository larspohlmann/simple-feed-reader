<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Bluesky\Support\AtPostUri;
use App\Service\Content\Comments\Model\CommentPostModel;
use App\Service\Content\Comments\Model\CommentViewerModel;

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
            null !== BlueskyRecordUri::likeOrNull($viewer->string('like')),
            null !== BlueskyRecordUri::repostOrNull($viewer->string('repost')),
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
