<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Comments\Model\CommentCountsModel;
use App\Service\Comments\Model\CommentPostModel;
use App\Service\Comments\Model\CommentViewerModel;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Url\Support\AbsoluteHttpUrl;

/** The post a reply in a thread answer is; null when the answer names no address or no counts for it. */
final class CommentPost
{
    private function __construct()
    {
    }

    public static function ofBlueskyPost(JsonNodeModel $post): ?CommentPostModel
    {
        $uri = $post->string('uri');
        $address = null !== $uri && AtPostUri::matches($uri) ? $uri : null;
        $viewer = $post->node('viewer');
        $commentViewer = new CommentViewerModel(
            null !== BlueskyRecordUri::likeOrNull($viewer->string('like')),
            null !== BlueskyRecordUri::repostOrNull($viewer->string('repost')),
            true,
        );

        return self::postOrNull($address, PostCounts::ofBlueskyPost($post), $commentViewer);
    }

    public static function ofMastodonStatus(JsonNodeModel $status): ?CommentPostModel
    {
        $address = AbsoluteHttpUrl::orNull($status->string('uri')) ?? AbsoluteHttpUrl::orNull($status->string('url'));

        $viewer = new CommentViewerModel(
            $status->isTrue('favourited'),
            $status->isTrue('reblogged'),
            MastodonVisibility::allowsReblog($status),
        );

        return self::postOrNull($address, PostCounts::ofMastodonStatus($status), $viewer);
    }

    private static function postOrNull(
        ?string $address,
        ?SocialCountsModel $counts,
        CommentViewerModel $viewer,
    ): ?CommentPostModel {
        if (null === $address || null === $counts) {
            return null;
        }
        $commentCounts = new CommentCountsModel($counts->likes, $counts->reposts, $counts->replies, $counts->quotes);

        return new CommentPostModel($address, $commentCounts, $viewer);
    }
}
