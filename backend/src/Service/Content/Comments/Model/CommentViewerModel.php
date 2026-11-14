<?php

declare(strict_types=1);

namespace App\Service\Content\Comments\Model;

/** What the reader has done to a comment's network post, and what the network lets them do with it. */
final readonly class CommentViewerModel
{
    public function __construct(
        public bool $isLiked,
        public bool $isReposted,
        public bool $canRepost,
        public bool $canReply = true,
        public bool $canQuote = true,
        public bool $isOwn = false,
    ) {
    }

    #[\NoDiscard]
    public function ownedByViewer(): self
    {
        return clone($this, ['isOwn' => true]);
    }
}
