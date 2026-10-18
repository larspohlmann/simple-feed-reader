<?php

declare(strict_types=1);

namespace App\Service\Comments\Model;

/** The network post a comment is: where a like goes, and what the reader sees counted on it. */
final readonly class CommentPostModel
{
    /** @param string $address the Bluesky AT URI or the Mastodon status URI a like resolves */
    public function __construct(
        public string $address,
        public CommentCountsModel $counts,
        public bool $isLiked,
    ) {
    }
}
