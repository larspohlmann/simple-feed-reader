<?php

declare(strict_types=1);

namespace App\Service\Comments\Model;

/** What the reader has done to a comment's network post, and whether the network lets them repost it. */
final readonly class CommentViewerModel
{
    public function __construct(
        public bool $isLiked,
        public bool $isReposted,
        public bool $canRepost,
        public bool $canReply = true,
        public bool $isOwn = false,
    ) {
    }

    public function ownedByViewer(): self
    {
        return new self($this->isLiked, $this->isReposted, $this->canRepost, $this->canReply, isOwn: true);
    }
}
