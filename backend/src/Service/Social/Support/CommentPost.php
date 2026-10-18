<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Comments\Model\CommentCountsModel;
use App\Service\Comments\Model\CommentPostModel;
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
        $isLiked = null !== BlueskyRecordUri::likeOrNull($post->node('viewer')->string('like'));

        return self::postOrNull($address, PostCounts::ofBlueskyPost($post), $isLiked);
    }

    public static function ofMastodonStatus(JsonNodeModel $status): ?CommentPostModel
    {
        $address = AbsoluteHttpUrl::orNull($status->string('uri')) ?? AbsoluteHttpUrl::orNull($status->string('url'));

        return self::postOrNull($address, PostCounts::ofMastodonStatus($status), $status->isTrue('favourited'));
    }

    private static function postOrNull(?string $address, ?SocialCountsModel $counts, bool $isLiked): ?CommentPostModel
    {
        if (null === $address || null === $counts) {
            return null;
        }
        $commentCounts = new CommentCountsModel($counts->likes, $counts->reposts, $counts->replies, $counts->quotes);

        return new CommentPostModel($address, $commentCounts, $isLiked);
    }
}
