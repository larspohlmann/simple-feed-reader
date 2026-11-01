<?php

declare(strict_types=1);

namespace App\Service\Comments\Model;

/** The network post a comment is: where a like or repost goes, and what the reader sees counted on it. */
final readonly class CommentPostModel
{
    /** @param string $address the Bluesky AT URI or the Mastodon status URI a like or repost resolves */
    public function __construct(
        public string $address,
        public CommentCountsModel $counts,
        public CommentViewerModel $viewer,
    ) {
    }
}
