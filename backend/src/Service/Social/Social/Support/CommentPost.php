<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Bluesky\Support\AtPostUri;
use App\Service\Content\Comments\Model\CommentCountsModel;
use App\Service\Content\Comments\Model\CommentPostModel;
use App\Service\Content\Comments\Model\CommentViewerModel;
use App\Service\Social\Social\Model\SocialCountsModel;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;

/** The post a reply in a thread answer is; null when the answer names no address or no counts for it. */
final class CommentPost
{
    private function __construct()
    {
    }

    public static function ofBlueskyPost(JsonNodeModel $post): ?CommentPostModel
    {
        $uri = $post->string('uri');
        $counts = PostCounts::ofBlueskyPost($post);
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
            self::commentCountsOf($counts),
            $commentViewer,
            $post->node('author')->string('did'),
        );
    }

    public static function ofMastodonStatus(JsonNodeModel $status): ?CommentPostModel
    {
        $address = AbsoluteHttpUrl::orNull($status->string('uri')) ?? AbsoluteHttpUrl::orNull($status->string('url'));

        $counts = PostCounts::ofMastodonStatus($status);
        if (null === $address || null === $counts) {
            return null;
        }
        $viewer = new CommentViewerModel(
            $status->isTrue('favourited'),
            $status->isTrue('reblogged'),
            MastodonVisibility::allowsReblog($status),
            canQuote: MastodonQuoting::allows($status),
        );

        return new CommentPostModel(
            $address,
            self::commentCountsOf($counts),
            $viewer,
            MastodonAuthor::identity($status->node('account')),
        );
    }

    private static function commentCountsOf(SocialCountsModel $counts): CommentCountsModel
    {
        return new CommentCountsModel($counts->likes, $counts->reposts, $counts->replies, $counts->quotes);
    }
}
