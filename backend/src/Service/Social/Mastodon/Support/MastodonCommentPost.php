<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon\Support;

use App\Service\Content\Comments\Model\CommentPostModel;
use App\Service\Content\Comments\Model\CommentViewerModel;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;

/** The post a reply in a Mastodon context answer is; null when the answer names no address or no counts for it. */
final class MastodonCommentPost
{
    private function __construct()
    {
    }

    public static function parseStatus(JsonNodeModel $status): ?CommentPostModel
    {
        $address = AbsoluteHttpUrl::keepIfAbsolute($status->string('uri'))
            ?? AbsoluteHttpUrl::keepIfAbsolute($status->string('url'));

        $counts = MastodonPostCounts::parseStatus($status);
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
            $counts->toCommentCounts(),
            $viewer,
            MastodonAuthor::identity($status->node('account')),
        );
    }
}
